<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\GameSystem\Flow\ChoiceStep;
use App\Play\Domain\GameSystem\Flow\ConditionStep;
use App\Play\Domain\GameSystem\Flow\Flow;
use App\Play\Domain\GameSystem\Flow\Outcome;
use App\Play\Domain\GameSystem\Flow\Phase;
use App\Play\Domain\GameSystem\Flow\PhaseMode;
use App\Play\Domain\GameSystem\Flow\PromptStep;
use App\Play\Domain\GameSystem\Flow\RollStep;
use App\Play\Domain\GameSystem\Flow\SelectionRule;
use App\Play\Domain\GameSystem\Flow\Step;
use App\Play\Domain\GameSystem\Flow\StepList;
use App\Play\Domain\GameSystem\Flow\TableStep;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\SceneType;
use App\Randomness\Domain\DiceExpression;
use App\Randomness\Domain\Oracle\OracleTableResult;

/**
 * A campaign's way along its Flow (ADR 0017 §1, ADR 0018), part of the Campaign aggregate, which
 * drives it through a FlowRunContext.
 *
 * Stages: at the scene pick, the phase's selection offers Scene Types (a selection with one picks
 * it as soon as a session is under way); picking starts a scene of play. In a scene the parts run
 * in order (ScenePart): scene opening, setup, play, open play until "End scene", closing, scene
 * closing. After the scene the phase goes on at the scene pick, or ends: a once phase after its
 * scenes, a loop phase on "Move on" (at once at the pick, else after the scene) or when an effect
 * ends it. After the last phase the FlowRun is completed. Steps advance to the chosen option's
 * "next", else the step's "next", else the following step; "end" ends the part; a condition step
 * advances on its own.
 *
 * Paused, the FlowRun takes no command and the player plays freely; resuming goes on where it
 * stopped. A guided scene that is no longer the current scene when guidance resumes or a session
 * starts (the player started another by hand, or the session ended) is abandoned: the FlowRun goes
 * on after it, at the scene pick.
 *
 * Not run yet (play-flow-run slice 16): session, phase and world turn hooks, effects, bands and
 * oracle/table branches, placeholders. The seams are marked "Hooks:" below.
 */
final class FlowRun
{
    private FlowRunStatus $status = FlowRunStatus::Active;

    private int $phaseIndex = 0;

    private FlowRunStage $stage = FlowRunStage::ScenePick;

    private ?int $sessionNumber = null;

    private ?int $sceneNumber = null;

    private ?string $sceneType = null;

    private ?ScenePart $part = null;

    private ?string $stepKey = null;

    private int $sequencePosition = 0;

    private int $scenesPlayed = 0;

    /** @var array<string, string> the latest answer text by step key */
    private array $answers = [];

    private ?string $forcedNextSceneType = null;

    /** why the phase ends once the current scene finishes ("endPhase" or "moveOn"); null while it goes on */
    private ?string $phaseEnding = null;

    private int $switchCount = 0;

    /** @var list<FlowRunHistoryEntry> */
    private array $history = [];

    private function __construct()
    {
    }

    /**
     * An active FlowRun at the scene pick of the first phase, waiting for a session.
     */
    public static function start(): self
    {
        return new self();
    }

    /**
     * A session started: at the scene pick, a selection with one Scene Type picks it; a guided
     * scene of an earlier session is abandoned.
     */
    public function sessionStarted(FlowRunContext $context): void
    {
        // Hooks: the session opening runs here.
        if (FlowRunStatus::Active !== $this->status) {
            return;
        }

        if (FlowRunStage::ScenePick === $this->stage) {
            $this->reachScenePick($context);
        } elseif (!$this->inCurrentScene($context)) {
            $this->abandonScene($context);
        }
    }

    /**
     * Completes the current step with a result of its kind, records its answer text and advances.
     *
     * @throws FlowRunNotActive        when the Flow is complete
     * @throws FlowRunPositionMismatch when the current step is not this one
     * @throws InvalidStepResult       when the result does not complete the step
     */
    public function completeStep(string $stepKey, StepResult $result, FlowRunContext $context): void
    {
        $step = $this->expectStep($stepKey, $context);
        $kind = StepKind::of($step);
        if ($result->kind !== $kind) {
            throw InvalidStepResult::kind($step->key, $kind, $result->kind);
        }

        $outcome = null;
        $answer = $result->answer;
        if ($step instanceof ChoiceStep) {
            $option = $step->option($answer) ?? throw InvalidStepResult::unknownOption($step->key, $answer);
            [$outcome, $answer] = [$option->outcome, $option->label];
        } elseif ($step instanceof TableStep && $result->table?->steps()[0]->tableKey() !== $step->table) {
            throw InvalidStepResult::otherTable($step->key, $step->table);
        } elseif ($step instanceof RollStep && $result->roll?->expression()->notation() !== DiceExpression::fromString($step->dice)->notation()) {
            throw InvalidStepResult::otherDice($step->key, $step->dice);
        } elseif ($step instanceof PromptStep && '' === $answer) {
            throw InvalidStepResult::blankAnswer($step->key);
        }

        $this->answers[$step->key] = $answer;
        $this->advance($step, $outcome, $context);
    }

    /**
     * Skips the current suggested step (recorded in the history): a choice follows its "skip"
     * option, any other step its "next".
     *
     * @throws FlowRunNotActive        when the Flow is complete
     * @throws FlowRunPositionMismatch when the current step is not this one
     * @throws StepCannotBeSkipped     when the step is mandatory
     */
    public function skipStep(string $stepKey, FlowRunContext $context): void
    {
        $step = $this->expectStep($stepKey, $context);
        if ($step->mandatory) {
            throw StepCannotBeSkipped::mandatory($step->key);
        }

        $this->history[] = FlowRunHistoryEntry::skip($context->at, $step->key, $this->part ?? ScenePart::Open);
        $skip = $step instanceof ChoiceStep && null !== $step->skip ? $step->option($step->skip) : null;
        $this->advance($step, $skip?->outcome, $context);
    }

    /**
     * Ends open play in the guided scene: its closing starts.
     *
     * @param int $sceneNumber the guided scene, by its number in the session
     *
     * @throws FlowRunNotActive        when the Flow is complete
     * @throws FlowRunPositionMismatch when the FlowRun is not in open play in that scene
     */
    public function endScene(int $sceneNumber, FlowRunContext $context): void
    {
        $this->assertActive();
        if (ScenePart::Open !== $this->part || $sceneNumber !== $this->sceneNumber) {
            throw FlowRunPositionMismatch::at(\sprintf('open play in scene %d', $sceneNumber), $this->where());
        }

        $this->assertInCurrentScene($context);
        $this->enterPart(ScenePart::Closing, $context);
    }

    /**
     * Picks an offered Scene Type at the scene pick and starts its scene.
     *
     * @throws FlowRunNotActive        when the Flow is complete
     * @throws FlowRunPositionMismatch when the FlowRun is not at the scene pick
     * @throws SceneTypeNotOffered     when the pick does not offer it, or rolls on a table
     */
    public function pickSceneType(string $sceneTypeKey, FlowRunContext $context): void
    {
        $this->assertAtScenePick();
        $selection = $this->phase($context->flow)->selection;
        if (null === $this->forcedNextSceneType && SelectionRule::Oracle === $selection->rule) {
            throw SceneTypeNotOffered::rollTheTable((string) $selection->table);
        }

        $offered = array_find($this->offered($context->release, $context->flow), static fn (SceneType $sceneType): bool => $sceneType->key === $sceneTypeKey);
        $this->startScene($offered ?? throw SceneTypeNotOffered::withKey($sceneTypeKey), $context);
    }

    /**
     * Picks the Scene Type of the entry rolled on the phase's oracle table and starts its scene.
     *
     * @throws FlowRunNotActive        when the Flow is complete
     * @throws FlowRunPositionMismatch when the FlowRun is not at the scene pick
     * @throws SceneTypeNotOffered     when the pick does not roll, the result is of another table or
     *                                 its entry names no Scene Type
     */
    public function pickSceneTypeByOracle(OracleTableResult $result, FlowRunContext $context): void
    {
        $this->assertAtScenePick();
        $selection = $this->phase($context->flow)->selection;
        if (null !== $this->forcedNextSceneType || SelectionRule::Oracle !== $selection->rule) {
            throw SceneTypeNotOffered::noRoll();
        }

        $rolled = $result->steps()[0];
        if ($rolled->tableKey() !== $selection->table) {
            throw SceneTypeNotOffered::rollTheTable((string) $selection->table);
        }

        $key = $context->release->rolledEntry($rolled)->sceneType ?? throw SceneTypeNotOffered::entryWithoutSceneType($rolled->tableKey());
        $this->startScene($context->sceneType($key), $context);
    }

    /**
     * Ends a loop phase by the player's choice: at once at the scene pick, else once the current
     * scene finishes (its closing parts still run).
     *
     * @param string $phaseKey the phase to end, the current one
     *
     * @throws FlowRunNotActive        when guidance is paused or the Flow is complete
     * @throws FlowRunPositionMismatch when the current phase is another, or the guided scene is no
     *                                 longer the current scene
     * @throws MoveOnNotAllowed        when the phase plays once
     */
    public function moveOn(string $phaseKey, FlowRunContext $context): void
    {
        $this->assertActive();
        $phase = $this->phase($context->flow);
        if ($phaseKey !== $phase->key) {
            throw FlowRunPositionMismatch::at(\sprintf('phase "%s"', $phaseKey), \sprintf('phase "%s"', $phase->key));
        }

        if (PhaseMode::Once === $phase->mode) {
            throw MoveOnNotAllowed::oncePhase($phase->key);
        }

        if (FlowRunStage::Scene === $this->stage) {
            $this->assertInCurrentScene($context);
        }

        if (FlowRunStage::ScenePick === $this->stage) {
            $this->finishPhase('moveOn', $context);
        } else {
            $this->phaseEnding ??= 'moveOn';
        }
    }

    /**
     * Turns guidance off: the player plays freely, and resume() goes on where it stopped.
     *
     * @throws FlowRunNotActive when guidance is already paused or the Flow is complete
     */
    public function pause(\DateTimeImmutable $at): void
    {
        if (FlowRunStatus::Active !== $this->status) {
            throw FlowRunNotActive::toPause($this->status);
        }

        $this->status = FlowRunStatus::Paused;
        $this->history[] = FlowRunHistoryEntry::paused($at);
    }

    /**
     * Turns guidance back on at the stored position. A guided scene that is no longer the current
     * one is abandoned, and the FlowRun goes on after it, at the scene pick.
     *
     * @throws FlowRunNotActive when guidance is not paused
     */
    public function resume(FlowRunContext $context): void
    {
        if (FlowRunStatus::Paused !== $this->status) {
            throw FlowRunNotActive::toResume($this->status);
        }

        $this->status = FlowRunStatus::Active;
        $this->history[] = FlowRunHistoryEntry::resumed($context->at);
        if (FlowRunStage::ScenePick === $this->stage) {
            $this->reachScenePick($context);
        } elseif (!$this->inCurrentScene($context)) {
            $this->abandonScene($context);
        }
    }

    /**
     * Records a Tracker edited by hand.
     */
    public function recordTrackerEdit(string $tracker, int $from, int $to, \DateTimeImmutable $at): void
    {
        $this->history[] = FlowRunHistoryEntry::trackerEdit($at, $tracker, $from, $to);
    }

    /**
     * Records the current scene's Scene Type switched by hand. While guidance is active and it is
     * the guided scene, the FlowRun follows: it goes on at the new type's setup (the scene opening
     * does not run again). Paused, it stays where resume() goes on.
     *
     * @param ?string $from the scene's Scene Type key before the switch
     */
    public function followSceneTypeSwitch(?string $from, SceneType $to, FlowRunContext $context): void
    {
        $this->history[] = FlowRunHistoryEntry::sceneTypeSwitch($context->at, (int) $context->sceneNumber(), $from, $to->key);
        if (FlowRunStatus::Active === $this->status && FlowRunStage::Scene === $this->stage && $this->inCurrentScene($context)) {
            $this->sceneType = $to->key;
            $this->enterPart(ScenePart::Setup, $context);
        }
    }

    /**
     * Forces the Scene Type of the next scene pick: the pick offers only it, and it does not
     * advance a sequence (set by a nextScene effect).
     */
    public function forceNextSceneType(string $sceneTypeKey): void
    {
        $this->forcedNextSceneType = $sceneTypeKey;
    }

    /**
     * Ends the phase once the current scene finishes (set by an endPhase effect).
     */
    public function endPhaseAfterScene(): void
    {
        $this->phaseEnding ??= 'endPhase';
    }

    /**
     * What the player sees of the FlowRun.
     *
     * @param ?int $sessionNumber the number of the session under way, null when none is
     */
    public function view(GameSystemSnapshot $release, Flow $flow, ?int $sessionNumber): FlowRunView
    {
        if (FlowRunStatus::Completed === $this->status) {
            return new FlowRunView($this->status, false, null, null, null, false, 'Flow complete');
        }

        $phase = $this->phase($flow);
        $step = $this->currentStep($release, $flow);
        $shown = $this->part instanceof ScenePart ? array_values(array_filter($this->steps($this->part, $release, $flow)->steps, static fn (Step $step): bool => !$step instanceof ConditionStep)) : [];
        $number = $step instanceof Step ? array_search($step, $shown, true) : false;
        $pick = FlowRunStage::ScenePick === $this->stage ? new ScenePickView(
            $phase->selection->rule,
            $this->offered($release, $flow),
            null === $this->forcedNextSceneType ? $phase->selection->table : null,
            null !== $this->forcedNextSceneType,
        ) : null;

        return new FlowRunView(
            $this->status,
            null === $sessionNumber,
            new FlowRunProgress($phase->act, $phase->name, null === $this->sceneType ? null : $this->sceneTypeIn($release)->name, $this->part, false === $number ? null : $number + 1, false === $number ? null : \count($shown)),
            $step instanceof Step ? new FlowStepView(StepKind::of($step), $step) : null,
            $pick,
            FlowRunStatus::Active === $this->status && PhaseMode::Loop === $phase->mode && null === $this->phaseEnding,
            $this->nextLabel($step, $release, $flow),
        );
    }

    /**
     * Takes the state of a copy of this FlowRun (the Campaign runs commands on a copy and keeps the
     * outcome only when they succeed).
     */
    public function replaceWith(self $copy): void
    {
        foreach (get_object_vars($copy) as $property => $value) {
            $this->{$property} = $value;
        }
    }

    public function status(): FlowRunStatus
    {
        return $this->status;
    }

    /**
     * The current phase's index in the Flow, from 0 (the phase count once completed).
     */
    public function phaseIndex(): int
    {
        return $this->phaseIndex;
    }

    public function stage(): FlowRunStage
    {
        return $this->stage;
    }

    /**
     * The guided scene's session and its number in it; null outside a scene.
     *
     * @return ?array{int, int}
     */
    public function scene(): ?array
    {
        return null === $this->sessionNumber || null === $this->sceneNumber ? null : [$this->sessionNumber, $this->sceneNumber];
    }

    public function sceneType(): ?string
    {
        return $this->sceneType;
    }

    public function part(): ?ScenePart
    {
        return $this->part;
    }

    /**
     * The current step's key; null at the scene pick and in open play.
     */
    public function stepKey(): ?string
    {
        return $this->stepKey;
    }

    /**
     * How many Scene Types of the phase's sequence were picked (forced picks do not count).
     */
    public function sequencePosition(): int
    {
        return $this->sequencePosition;
    }

    /**
     * How many scenes the phase started.
     */
    public function scenesPlayed(): int
    {
        return $this->scenesPlayed;
    }

    /**
     * @return array<string, string> the latest answer text by step key
     */
    public function answers(): array
    {
        return $this->answers;
    }

    public function forcedNextSceneType(): ?string
    {
        return $this->forcedNextSceneType;
    }

    /**
     * Whether the phase ends once the current scene finishes (an effect or "Move on" ended it).
     */
    public function phaseEnding(): bool
    {
        return null !== $this->phaseEnding;
    }

    /**
     * How many times effects switched the guided scene's Scene Type (at most one counts).
     */
    public function switchCount(): int
    {
        return $this->switchCount;
    }

    /**
     * @return list<FlowRunHistoryEntry> in the order things happened
     */
    public function history(): array
    {
        return $this->history;
    }

    /**
     * @throws FlowRunNotActive
     * @throws FlowRunPositionMismatch
     */
    private function expectStep(string $stepKey, FlowRunContext $context): Step
    {
        $this->assertActive();
        if (null === $this->stepKey || $stepKey !== $this->stepKey) {
            throw FlowRunPositionMismatch::at(\sprintf('step "%s"', $stepKey), $this->where());
        }

        $this->assertInCurrentScene($context);

        return $this->currentStep($context->release, $context->flow) ?? throw new \LogicException('The current step is in its list.');
    }

    /**
     * @throws FlowRunNotActive
     * @throws FlowRunPositionMismatch
     */
    private function assertAtScenePick(): void
    {
        $this->assertActive();
        if (FlowRunStage::ScenePick !== $this->stage) {
            throw FlowRunPositionMismatch::at('the scene pick', $this->where());
        }
    }

    /**
     * @throws FlowRunNotActive
     */
    private function assertActive(): void
    {
        if (FlowRunStatus::Active !== $this->status) {
            throw FlowRunNotActive::toPlay($this->status);
        }
    }

    /**
     * @throws FlowRunPositionMismatch
     */
    private function assertInCurrentScene(FlowRunContext $context): void
    {
        if (!$this->inCurrentScene($context)) {
            throw FlowRunPositionMismatch::sceneNotCurrent();
        }
    }

    private function inCurrentScene(FlowRunContext $context): bool
    {
        return $context->sessionNumber() === $this->sessionNumber && $context->sceneNumber() === $this->sceneNumber;
    }

    private function where(): string
    {
        return match (true) {
            FlowRunStage::ScenePick === $this->stage => 'the scene pick',
            ScenePart::Open === $this->part => \sprintf('open play in scene %d', $this->sceneNumber),
            default => \sprintf('step "%s"', $this->stepKey),
        };
    }

    /**
     * At the scene pick: with a session under way, a selection with one Scene Type picks it (a
     * forced Scene Type and an oracle selection wait for the player).
     */
    private function reachScenePick(FlowRunContext $context): void
    {
        $this->stage = FlowRunStage::ScenePick;
        if (null === $context->sessionNumber() || null !== $this->forcedNextSceneType) {
            return;
        }

        $only = $this->phase($context->flow)->selection->autoPick();
        if (null !== $only) {
            $this->startScene($context->sceneType($only), $context);
        }
    }

    private function startScene(SceneType $sceneType, FlowRunContext $context): void
    {
        $this->sceneNumber = $context->startScene($sceneType);
        $this->sessionNumber = $context->sessionNumber();
        $this->stage = FlowRunStage::Scene;
        $this->sceneType = $sceneType->key;
        $this->switchCount = 0;
        ++$this->scenesPlayed;
        if (null !== $this->forcedNextSceneType) {
            $this->forcedNextSceneType = null;
        } elseif (SelectionRule::Sequence === $this->phase($context->flow)->selection->rule) {
            ++$this->sequencePosition;
        }

        $this->enterPart(ScenePart::SceneOpening, $context);
    }

    /**
     * Enters a part at its first step; an empty part is passed, open play waits for "End scene".
     */
    private function enterPart(ScenePart $part, FlowRunContext $context): void
    {
        $this->part = $part;
        $this->stepKey = null;
        if (ScenePart::Open === $part) {
            return;
        }

        $first = $this->steps($part, $context->release, $context->flow)->steps[0] ?? null;
        if (null === $first) {
            $this->leavePart($context);

            return;
        }

        $this->stepKey = $first->key;
        $this->settle($context);
    }

    /**
     * Moves on from a finished step: to the outcome's "next", else the step's, else the following
     * step; "end" or no following step ends the part.
     */
    private function advance(Step $step, ?Outcome $outcome, FlowRunContext $context): void
    {
        $part = $this->part ?? throw new \LogicException('A step runs in a part.');
        $target = $this->target($this->steps($part, $context->release, $context->flow), $step, $outcome);
        if (!$target instanceof Step) {
            $this->leavePart($context);

            return;
        }

        $this->stepKey = $target->key;
        $this->settle($context);
    }

    /**
     * A condition step never waits: it advances on its own (its bands are evaluated with effects).
     */
    private function settle(FlowRunContext $context): void
    {
        $step = $this->currentStep($context->release, $context->flow);
        if ($step instanceof ConditionStep) {
            $this->advance($step, null, $context);
        }
    }

    private function leavePart(FlowRunContext $context): void
    {
        $next = $this->part?->next();
        if (!$next instanceof ScenePart) {
            $this->afterScene($context);
        } else {
            $this->enterPart($next, $context);
        }
    }

    private function afterScene(FlowRunContext $context): void
    {
        $this->stage = FlowRunStage::ScenePick;
        $this->sessionNumber = $this->sceneNumber = $this->sceneType = $this->part = $this->stepKey = null;
        $this->switchCount = 0;
        if ($this->phaseFinished($context->flow)) {
            // Hooks: the phase closing runs here.
            $this->finishPhase($this->phaseEnding ?? 'finished', $context);
        } else {
            // Hooks: the world turn runs here.
            $this->reachScenePick($context);
        }
    }

    /**
     * Leaves the guided scene unfinished (recorded) and goes on after it.
     */
    private function abandonScene(FlowRunContext $context): void
    {
        $this->history[] = FlowRunHistoryEntry::sceneAbandoned($context->at, (int) $this->sessionNumber, (int) $this->sceneNumber);
        $this->afterScene($context);
    }

    private function finishPhase(string $reason, FlowRunContext $context): void
    {
        $this->history[] = FlowRunHistoryEntry::phaseEnded($context->at, $this->phase($context->flow)->key, $reason);
        ++$this->phaseIndex;
        $this->sequencePosition = $this->scenesPlayed = 0;
        $this->phaseEnding = null;
        if (!$context->flow->phaseAt($this->phaseIndex) instanceof Phase) {
            $this->status = FlowRunStatus::Completed;
            $this->history[] = FlowRunHistoryEntry::completed($context->at);

            return;
        }

        // Hooks: the next phase's opening runs here.
        $this->reachScenePick($context);
    }

    /**
     * Whether the phase ends once the current scene finishes: an effect ended it, or a once phase
     * played its sequence (one scene for a player or oracle selection).
     */
    private function phaseFinished(Flow $flow): bool
    {
        $phase = $this->phase($flow);
        $selection = $phase->selection;
        $played = SelectionRule::Sequence === $selection->rule ? $this->sequencePosition >= \count($selection->sceneTypes) : $this->scenesPlayed >= 1;

        return null !== $this->phaseEnding || (PhaseMode::Once === $phase->mode && $played);
    }

    /**
     * @return list<SceneType> the Scene Types the scene pick offers: the forced one, the next of a
     *                         sequence (a loop cycles), a player selection's, none to roll for
     */
    private function offered(GameSystemSnapshot $release, Flow $flow): array
    {
        $selection = $this->phase($flow)->selection;
        $keys = null !== $this->forcedNextSceneType ? [$this->forcedNextSceneType] : match ($selection->rule) {
            SelectionRule::Sequence => [$selection->sceneTypes[$this->sequencePosition % \count($selection->sceneTypes)]],
            SelectionRule::Player => $selection->sceneTypes,
            SelectionRule::Oracle => [],
        };

        return array_map(static fn (string $key): SceneType => $release->sceneType($key) ?? throw new \LogicException(\sprintf('The pinned release has no Scene Type "%s".', $key)), $keys);
    }

    /**
     * The next step named: the step after the current one on its default path (condition steps
     * are passed), across parts, past the scene to the next pick or phase.
     */
    private function nextLabel(?Step $step, GameSystemSnapshot $release, Flow $flow): string
    {
        if (!$this->part instanceof ScenePart) {
            return $this->pickLabel($release, $flow);
        }

        $part = $this->part;
        $steps = $this->steps($part, $release, $flow);
        $next = $step instanceof Step ? $this->effective($steps, $this->target($steps, $step, null)) : null;
        while (!$next instanceof Step) {
            $part = $part->next();
            if (!$part instanceof ScenePart) {
                return $this->labelAfterScene($release, $flow);
            }

            if (ScenePart::Open === $part) {
                return $part->label();
            }

            $steps = $this->steps($part, $release, $flow);
            $next = $this->effective($steps, $steps->steps[0] ?? null);
        }

        return $part->label().': '.$next->title;
    }

    private function labelAfterScene(GameSystemSnapshot $release, Flow $flow): string
    {
        // Hooks: the scene closing, phase closing and world turn hooks are named here.
        if (!$this->phaseFinished($flow)) {
            return $this->pickLabel($release, $flow);
        }

        $next = $flow->phaseAt($this->phaseIndex + 1);

        return $next instanceof Phase ? \sprintf('%s complete → %s', $this->phase($flow)->name, $next->name) : 'Flow complete';
    }

    /**
     * The scene pick named: the next of a sequence, the forced or only Scene Type, else the choice
     * or the table to roll.
     */
    private function pickLabel(GameSystemSnapshot $release, Flow $flow): string
    {
        $selection = $this->phase($flow)->selection;
        $offered = $this->offered($release, $flow);
        if (null === $this->forcedNextSceneType && SelectionRule::Sequence === $selection->rule) {
            return 'Next: '.$offered[0]->name;
        }

        if (1 === \count($offered)) {
            return 'Next scene: '.$offered[0]->name;
        }

        return SelectionRule::Oracle === $selection->rule
            ? 'Next scene: roll on '.($release->oracleTableNames()[(string) $selection->table] ?? $selection->table)
            : 'Next scene: choose a scene type';
    }

    /**
     * The step a player meets from this one: condition steps pass on to their next.
     */
    private function effective(StepList $steps, ?Step $step): ?Step
    {
        while ($step instanceof ConditionStep) {
            $step = $this->target($steps, $step, null);
        }

        return $step;
    }

    /**
     * The step after this one on its default path (condition steps are passed), else null.
     */
    private function target(StepList $steps, Step $step, ?Outcome $outcome): ?Step
    {
        $next = $outcome->next ?? $step->next;
        if ('end' === $next) {
            return null;
        }

        if (null !== $next) {
            return $steps->step($next);
        }

        $index = array_search($step, $steps->steps, true);

        return false === $index ? null : ($steps->steps[$index + 1] ?? null);
    }

    private function currentStep(GameSystemSnapshot $release, Flow $flow): ?Step
    {
        return !$this->part instanceof ScenePart || null === $this->stepKey ? null : $this->steps($this->part, $release, $flow)->step($this->stepKey);
    }

    private function steps(ScenePart $part, GameSystemSnapshot $release, Flow $flow): StepList
    {
        return match ($part) {
            ScenePart::SceneOpening => $this->phase($flow)->sceneOpening,
            ScenePart::Setup => $this->sceneTypeIn($release)->setup,
            ScenePart::Play => $this->sceneTypeIn($release)->play,
            ScenePart::Open => new StepList(),
            ScenePart::Closing => $this->sceneTypeIn($release)->closing,
            ScenePart::SceneClosing => $this->phase($flow)->sceneClosing,
        };
    }

    private function phase(Flow $flow): Phase
    {
        return $flow->phaseAt($this->phaseIndex) ?? throw new \LogicException(\sprintf('Flow "%s" has no phase %d.', $flow->key, $this->phaseIndex));
    }

    private function sceneTypeIn(GameSystemSnapshot $release): SceneType
    {
        return $release->sceneType((string) $this->sceneType) ?? throw new \LogicException(\sprintf('The pinned release has no Scene Type "%s".', $this->sceneType));
    }
}
