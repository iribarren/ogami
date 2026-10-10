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
 * scenes, any phase on "Move on" (loop phases, at the pick) or when an effect ends it. After the
 * last phase the FlowRun is completed. Steps advance to the chosen option's "next", else the
 * step's "next", else the following step; "end" ends the part; a condition step advances on its own.
 *
 * Not run yet (play-flow-run slice 15): session, phase and world turn hooks, effects, bands and
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

    private bool $phaseEnding = false;

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
     * A session started: at the scene pick, a selection with one Scene Type picks it.
     */
    public function sessionStarted(FlowRunContext $context): void
    {
        // Hooks: the session opening runs here.
        if (FlowRunStatus::Active === $this->status && FlowRunStage::ScenePick === $this->stage) {
            $this->reachScenePick($context);
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
     * Ends a loop phase at its scene pick, by the player's choice.
     *
     * @throws FlowRunNotActive        when the Flow is complete
     * @throws FlowRunPositionMismatch when the FlowRun is not at the scene pick
     * @throws MoveOnNotAllowed        when the phase plays once
     */
    public function moveOn(FlowRunContext $context): void
    {
        $this->assertAtScenePick();
        $phase = $this->phase($context->flow);
        if (PhaseMode::Once === $phase->mode) {
            throw MoveOnNotAllowed::oncePhase($phase->key);
        }

        $this->finishPhase('moveOn', $context);
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
        $this->phaseEnding = true;
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

    public function phaseEnding(): bool
    {
        return $this->phaseEnding;
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
            throw FlowRunNotActive::completed();
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
            $this->finishPhase($this->phaseEnding ? 'endPhase' : 'finished', $context);
        } else {
            // Hooks: the world turn runs here.
            $this->reachScenePick($context);
        }
    }

    private function finishPhase(string $reason, FlowRunContext $context): void
    {
        $this->history[] = FlowRunHistoryEntry::phaseEnded($context->at, $this->phase($context->flow)->key, $reason);
        ++$this->phaseIndex;
        $this->sequencePosition = $this->scenesPlayed = 0;
        $this->phaseEnding = false;
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

        return $this->phaseEnding || (PhaseMode::Once === $phase->mode && $played);
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
