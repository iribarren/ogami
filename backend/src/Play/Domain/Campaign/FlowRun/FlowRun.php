<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Facts\Fact;
use App\Play\Domain\Campaign\Facts\FactLink;
use App\Play\Domain\Campaign\Hook;
use App\Play\Domain\Campaign\Lists\Npc;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\Thread;
use App\Play\Domain\Campaign\Lists\ThreadId;
use App\Play\Domain\Campaign\Scene;
use App\Play\Domain\GameSystem\FactSlot;
use App\Play\Domain\GameSystem\FactSlotType;
use App\Play\Domain\GameSystem\Flow\Band;
use App\Play\Domain\GameSystem\Flow\CharacterStep;
use App\Play\Domain\GameSystem\Flow\ChoiceStep;
use App\Play\Domain\GameSystem\Flow\CloseThreadEffect;
use App\Play\Domain\GameSystem\Flow\ConditionStep;
use App\Play\Domain\GameSystem\Flow\CreateNpcEffect;
use App\Play\Domain\GameSystem\Flow\CreateThreadEffect;
use App\Play\Domain\GameSystem\Flow\Effect;
use App\Play\Domain\GameSystem\Flow\EndPhaseEffect;
use App\Play\Domain\GameSystem\Flow\FillFactEffect;
use App\Play\Domain\GameSystem\Flow\Flow;
use App\Play\Domain\GameSystem\Flow\ItemCount;
use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Play\Domain\GameSystem\Flow\NextSceneEffect;
use App\Play\Domain\GameSystem\Flow\OracleStep;
use App\Play\Domain\GameSystem\Flow\Outcome;
use App\Play\Domain\GameSystem\Flow\Phase;
use App\Play\Domain\GameSystem\Flow\PhaseMode;
use App\Play\Domain\GameSystem\Flow\PickStep;
use App\Play\Domain\GameSystem\Flow\PromptStep;
use App\Play\Domain\GameSystem\Flow\RollStep;
use App\Play\Domain\GameSystem\Flow\SceneTitleEffect;
use App\Play\Domain\GameSystem\Flow\SelectionRule;
use App\Play\Domain\GameSystem\Flow\Step;
use App\Play\Domain\GameSystem\Flow\StepEffects;
use App\Play\Domain\GameSystem\Flow\StepList;
use App\Play\Domain\GameSystem\Flow\SwitchSceneTypeEffect;
use App\Play\Domain\GameSystem\Flow\TableStep;
use App\Play\Domain\GameSystem\Flow\TrackerEffect;
use App\Play\Domain\GameSystem\Flow\TrackerReference;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\SceneType;
use App\Play\Domain\GameSystem\Tag;
use App\Randomness\Domain\DiceExpression;
use App\Randomness\Domain\Oracle\OracleTableResult;

/**
 * A campaign's way along its Flow (ADR 0017 §1, ADR 0018), part of the Campaign aggregate, which
 * drives it through a FlowRunContext.
 *
 * Stages: at the scene pick, the phase's selection offers Scene Types (a selection with one picks
 * it itself when a session starts or a phase opens, ADR 0020; after a scene the pick waits for the
 * player, who plays the next scene or ends the session); picking starts a scene of play. In a scene the parts run
 * in order (ScenePart): scene opening, setup, play, open play until "End scene", closing, scene
 * closing. After the scene the phase goes on (world turn, then the scene pick), or ends: a once
 * phase after its scenes, a loop phase on "Move on" or when an Effect ends it (both take effect
 * after the scene, or where the FlowRun would reach the scene pick). An ending phase runs its phase
 * closing, then the next phase starts; after the last phase the FlowRun is completed.
 *
 * Hooks: on the way to the scene pick a session that started runs its session opening first, and
 * a phase entered for the first time its phase opening. A hook runs its steps with the same
 * mechanics as a scene's parts, in its own hook Scene (started at the first step that waits for
 * the player), and ends after its last step (no open play); an empty hook, or one its condition
 * steps pass, creates no Scene. A switchSceneType Effect in a hook is ignored (recorded). A hook
 * that comes up while no session is under way waits for the next session, and runs before that
 * session's opening.
 *
 * A finished step takes the Outcome of its result: an oracle answer's branch (an exceptional answer
 * without one uses yes / no), the branch of the entry rolled on a table step's own table (else
 * "otherwise"), the band of a roll total or of a condition step's Tracker, the chosen option, "found"
 * or "none" for a pick step. Its
 * Effects apply in order: the step's, those of the table entries it rolled (nested tables
 * included), then the Outcome's. Then it advances to the Outcome's "next", else the step's "next",
 * else the following step; "end" ends the part; a condition step advances on its own. The first
 * switchSceneType Effect of a scene changes its Scene Type in place and goes on at the new type's
 * setup instead; later ones are ignored (recorded). Hand switches do not count.
 *
 * A pick step's picked items are "{picked}" in the Scene they were picked in: the most recent pick
 * replaces the previous one (none picked leaves nothing picked), and a Scene ending or starting
 * clears them. A createNpc or createThread Effect creates an open item that becomes "{picked}"
 * instead, and a closeThread Effect closes the Threads picked. NPCs picked or created join the
 * Scene's cast.
 *
 * Paused, the FlowRun takes no command and the player plays freely; resuming goes on where it
 * stopped. A guided scene or hook Scene that is no longer the current scene when guidance resumes
 * or a session starts (the player started another by hand, or the session ended) is abandoned: the
 * FlowRun goes on as after it.
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

    /** the hook running (stage Hook), null outside a hook */
    private ?Hook $hook = null;

    /** whether the current phase's opening ran (or was passed) */
    private bool $phaseOpened = false;

    /** whether a session started whose opening has not run yet */
    private bool $sessionOpeningDue = false;

    /** whether the scene pick picks a selection's only Scene Type itself: a session started or a phase opened since the last scene */
    private bool $autoPickDue = false;

    /** the items "{picked}" stands for in the current Scene, null when nothing was picked in it */
    private ?PickedItems $picked = null;

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
     * Rebuilds a stored FlowRun, e.g. from persistence. No rule is checked again.
     *
     * @param ?int                      $sessionNumber     the guided scene's or hook Scene's session, null outside one
     * @param ?int                      $sceneNumber       the guided scene's or hook Scene's number in it, null outside one
     * @param array<string, string>     $answers           the latest answer text by step key
     * @param ?string                   $phaseEnding       why the phase ends ("endPhase", "moveOn", or "finished" while it closes), null while it goes on
     * @param list<FlowRunHistoryEntry> $history           in the order things happened
     * @param ?Hook                     $hook              the hook running at stage Hook, else null
     * @param bool                      $phaseOpened       whether the current phase's opening ran
     * @param bool                      $sessionOpeningDue whether a session started whose opening has not run yet
     * @param ?PickedItems              $picked            the items "{picked}" stands for, null when nothing was picked in the Scene
     * @param bool                      $autoPickDue       whether the scene pick picks a selection's only Scene Type itself (a session started or a phase opened since the last scene)
     */
    public static function reconstitute(
        FlowRunStatus $status,
        int $phaseIndex,
        FlowRunStage $stage,
        ?int $sessionNumber,
        ?int $sceneNumber,
        ?string $sceneType,
        ?ScenePart $part,
        ?string $stepKey,
        int $sequencePosition,
        int $scenesPlayed,
        array $answers,
        ?string $forcedNextSceneType,
        ?string $phaseEnding,
        int $switchCount,
        array $history,
        ?Hook $hook = null,
        bool $phaseOpened = true,
        bool $sessionOpeningDue = false,
        ?PickedItems $picked = null,
        bool $autoPickDue = false,
    ): self {
        $flowRun = new self();
        $flowRun->status = $status;
        $flowRun->phaseIndex = $phaseIndex;
        $flowRun->stage = $stage;
        $flowRun->sessionNumber = $sessionNumber;
        $flowRun->sceneNumber = $sceneNumber;
        $flowRun->sceneType = $sceneType;
        $flowRun->part = $part;
        $flowRun->stepKey = $stepKey;
        $flowRun->sequencePosition = $sequencePosition;
        $flowRun->scenesPlayed = $scenesPlayed;
        $flowRun->answers = $answers;
        $flowRun->forcedNextSceneType = $forcedNextSceneType;
        $flowRun->phaseEnding = $phaseEnding;
        $flowRun->switchCount = $switchCount;
        $flowRun->history = $history;
        $flowRun->hook = $hook;
        $flowRun->phaseOpened = $phaseOpened;
        $flowRun->sessionOpeningDue = $sessionOpeningDue;
        $flowRun->picked = $picked;
        $flowRun->autoPickDue = $autoPickDue;

        return $flowRun;
    }

    /**
     * A session started: its opening is due. While guidance is active the FlowRun goes on: on the way
     * to the scene pick (the session opening first), into a hook that waited for a session, or past a
     * guided scene or hook Scene of an earlier session (abandoned). Paused, it goes on when resumed.
     */
    public function sessionStarted(FlowRunContext $context): void
    {
        if (FlowRunStatus::Completed === $this->status) {
            return;
        }

        $this->sessionOpeningDue = true;
        if (FlowRunStatus::Active === $this->status) {
            $this->goOn($context);
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
        $newlyPicked = null;
        $answer = $result->answer;
        $rolledEffects = [];
        if ($step instanceof ChoiceStep) {
            $option = $step->option($answer) ?? throw InvalidStepResult::unknownOption($step->key, $answer);
            [$outcome, $answer] = [$option->outcome, $option->label];
        } elseif ($step instanceof TableStep) {
            $rolled = $result->table?->steps() ?? [];
            if (!isset($rolled[0]) || $rolled[0]->tableKey() !== $step->table) {
                throw InvalidStepResult::otherTable($step->key, $step->table);
            }

            $outcome = $step->outcomeFor($context->release->rolledEntry($rolled[0])?->key);
            foreach ($rolled as $tableStep) {
                $rolledEffects = [...$rolledEffects, ...$context->release->rolledEntry($tableStep)->effects ?? []];
            }
        } elseif ($step instanceof RollStep) {
            if ($result->roll?->expression()->notation() !== DiceExpression::fromString($step->dice)->notation()) {
                throw InvalidStepResult::otherDice($step->key, $step->dice);
            }

            $outcome = $this->band($step->bands, $result->roll->total(), $context);
        } elseif ($step instanceof OracleStep) {
            $outcome = $result->likelihood instanceof \App\Randomness\Domain\Oracle\LikelihoodAnswer ? $step->branches->for($result->likelihood->answer()) : null;
        } elseif ($step instanceof PickStep) {
            if ($result->of !== $step->of) {
                throw InvalidStepResult::otherItems($step->key, $step->of->value);
            }

            // The names are the candidates' now, not the result's.
            $picked = $context->pickCandidates($step)->select($step, array_map(static fn (PickedItem $item): string => $item->id, $result->picked));
            $answer = StepResult::pick($step->of, $picked)->answer;
            $outcome = [] === $picked ? $step->none : $step->found;
            $newlyPicked = [] === $picked ? null : new PickedItems($step->of, array_map(static fn (PickedItem $item): string => $item->id, $picked));
        } elseif ($step instanceof CharacterStep) {
            $id = $result->characterId ?? '';
            // The answer is the Character's name now, so {step:key} and the history read it.
            $answer = $context->characterName($id) ?? throw InvalidStepResult::unknownCharacter($step->key, $id);
            $newlyPicked = new PickedItems(ItemKind::Character, [$id]);
        } elseif ($step instanceof PromptStep && '' === $answer) {
            throw InvalidStepResult::blankAnswer($step->key);
        }

        $this->answers[$step->key] = $answer;
        if ($step instanceof CharacterStep) {
            // Like a one-item pick, but "{picked}" stays as it was.
            $this->enterHookScene($context);
            $context->joinSceneCast($newlyPicked, FlowRunStage::Hook === $this->stage);
        } elseif ($step instanceof PickStep) {
            // A pick in a hook belongs to the hook Scene (it is under way once the step waits; this
            // makes sure of it), so entering it later cannot clear the pick.
            $this->enterHookScene($context);
            $this->picked = $newlyPicked;
            if ($newlyPicked instanceof PickedItems) {
                $context->joinSceneCast($newlyPicked, FlowRunStage::Hook === $this->stage);
            }
        }

        $this->finish($step, $outcome, [...$step->effects, ...$rolledEffects, ...$outcome->effects ?? []], $answer, $context);
    }

    /**
     * Skips the current suggested step (recorded in the history): a choice follows its "skip"
     * option with that option's Effects only, any other step its "next" without Effects. No answer
     * is recorded; the skip option's label is the "{answer}" of its Effects.
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

        $this->history[] = FlowRunHistoryEntry::skip($context->at, $step->key, $this->hook ?? $this->part ?? ScenePart::Open);
        $skip = $step instanceof ChoiceStep && null !== $step->skip ? $step->option($step->skip) : null;
        $this->finish($step, $skip?->outcome, $skip->outcome->effects ?? [], $skip?->label, $context);
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
     * Ends a loop phase by the player's choice: at once at the scene pick (its phase closing runs),
     * else once the current scene finishes (its closing parts still run) or, in a hook, where the
     * FlowRun would reach the scene pick.
     *
     * @param string $phaseKey the phase to end, the current one
     *
     * @throws FlowRunNotActive        when guidance is paused or the Flow is complete
     * @throws FlowRunPositionMismatch when the current phase is another, or the guided scene or hook
     *                                 Scene is no longer the current scene
     * @throws MoveOnNotAllowed        when the phase plays once, or is closing
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

        if (Hook::PhaseClosing === $this->hook) {
            throw MoveOnNotAllowed::closing($phase->key);
        }

        if (FlowRunStage::ScenePick !== $this->stage) {
            $this->assertInCurrentScene($context);
        }

        $this->phaseEnding ??= 'moveOn';
        if (FlowRunStage::ScenePick === $this->stage) {
            $this->closePhase($context);
        }
    }

    /**
     * Ends the session under way between scenes, at the scene pick: the session closing runs first
     * (in its hook Scene), and the session ends once it finishes, at once when it has no step that
     * waits. The FlowRun then waits for the next session.
     *
     * @throws FlowRunNotActive            when guidance is paused or the Flow is complete
     * @throws SessionCannotEndWhileGuided when the FlowRun is in a scene or a hook
     */
    public function endSession(FlowRunContext $context): void
    {
        $this->assertActive();
        if (FlowRunStage::ScenePick !== $this->stage) {
            throw SessionCannotEndWhileGuided::at($this->where());
        }

        $this->runHook(Hook::SessionClosing, $context);
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
     * Turns guidance back on at the stored position (see sessionStarted() for how it goes on). A
     * guided scene or hook Scene that is no longer the current one is abandoned, and the FlowRun goes
     * on as after it.
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
        $this->goOn($context);
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
     * advance a sequence (set by a nextScene Effect; the last one wins).
     */
    public function forceNextSceneType(string $sceneTypeKey): void
    {
        $this->forcedNextSceneType = $sceneTypeKey;
    }

    /**
     * Ends the phase once the current scene finishes, or where the FlowRun would reach the scene pick
     * (set by an endPhase Effect; the first reason, an Effect or "Move on", is kept).
     */
    public function endPhaseAfterScene(): void
    {
        $this->phaseEnding ??= 'endPhase';
    }

    /**
     * What the player sees of the FlowRun.
     *
     * @param ?int                               $sessionNumber the number of the session under way, null when none is
     * @param array<string, int>                 $trackerValues the campaign's value of every Tracker of the release, by key, for placeholders
     * @param array<string, string>              $factValues    the value of every Fact slot of the release, by key, for "{fact:key}"
     * @param list<string>                       $pickedNames   the current names of the picked items, for "{picked}"
     * @param \Closure(PickStep): PickCandidates $candidatesOf  the campaign's candidates for a pick step
     */
    public function view(GameSystemSnapshot $release, Flow $flow, ?int $sessionNumber, array $trackerValues, array $factValues, array $pickedNames, \Closure $candidatesOf): FlowRunView
    {
        $placeholders = new Placeholders($trackerValues, $this->answers, $factValues, picked: $pickedNames);
        if (FlowRunStatus::Completed === $this->status) {
            return new FlowRunView($this->status, false, null, null, null, false, 'Flow complete', false);
        }

        $phase = $this->phase($flow);
        $step = $this->currentStep($release, $flow);
        $shown = array_values(array_filter($this->stepList($release, $flow)->steps ?? [], static fn (Step $step): bool => !$step instanceof ConditionStep));
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
            new FlowRunProgress($phase->act, $phase->key, $phase->name, null === $this->sceneType ? null : $this->sceneTypeIn($release)->name, $this->hook ?? $this->part, false === $number ? null : $number + 1, false === $number ? null : \count($shown)),
            $step instanceof Step ? new FlowStepView(StepKind::of($step), $step, $placeholders->render($step->title), $this->rendered($step->prompt, $placeholders), $this->rendered($step->tip, $placeholders), $step instanceof PickStep ? $this->pickView($step, $release, $candidatesOf($step)) : null, $this->fills($step, $release)) : null,
            $pick,
            FlowRunStatus::Active === $this->status && PhaseMode::Loop === $phase->mode && null === $this->phaseEnding,
            $this->nextLabel($step, $release, $flow, $placeholders, null === $sessionNumber || $this->sessionOpeningDue),
            FlowRunStatus::Active === $this->status && FlowRunStage::ScenePick === $this->stage && null !== $sessionNumber,
        );
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
     * The hook running at stage Hook; null outside a hook.
     */
    public function hook(): ?Hook
    {
        return $this->hook;
    }

    /**
     * Whether the current phase's opening ran (or was passed, being empty).
     */
    public function phaseOpened(): bool
    {
        return $this->phaseOpened;
    }

    /**
     * Whether a session started whose opening has not run yet (guidance was paused, or a hook left
     * over from the last session runs first).
     */
    public function sessionOpeningDue(): bool
    {
        return $this->sessionOpeningDue;
    }

    /**
     * Whether the scene pick picks a selection's only Scene Type itself: a session started or a
     * phase opened since the last scene. After a scene the pick waits for the player.
     */
    public function autoPickDue(): bool
    {
        return $this->autoPickDue;
    }

    /**
     * The guided scene's or hook Scene's session and its number in it; null outside one.
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
     * The current step's key; null at the scene pick, in open play and in a hook waiting for a session.
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

    /**
     * The items "{picked}" stands for; null when nothing was picked in the current Scene.
     */
    public function picked(): ?PickedItems
    {
        return $this->picked;
    }

    public function forcedNextSceneType(): ?string
    {
        return $this->forcedNextSceneType;
    }

    /**
     * Whether the phase ends once the current scene finishes (an effect or "Move on" ended it), or
     * is closing.
     */
    public function phaseEnding(): bool
    {
        return null !== $this->phaseEnding;
    }

    /**
     * Why the phase ends once the current scene finishes: "endPhase" (an effect) or "moveOn"; while
     * it closes, that reason or "finished" (a once phase played its scenes); null while it goes on.
     */
    public function phaseEndingReason(): ?string
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
            null === $this->stepKey => \sprintf('the %s, waiting for a session', mb_strtolower($this->hook?->label() ?? '')),
            default => \sprintf('step "%s"', $this->stepKey),
        };
    }

    /**
     * Goes on from the stored position when guidance resumes or a session starts: on the way to the
     * scene pick, into a hook that waits for a session, or past a guided scene or hook Scene that is
     * no longer the current scene (abandoned).
     */
    private function goOn(FlowRunContext $context): void
    {
        if (FlowRunStage::ScenePick === $this->stage) {
            $this->reachScenePick($context);
        } elseif ($this->hook instanceof Hook && null === $this->sceneNumber) {
            $this->runHook($this->hook, $context);
        } elseif (!$this->inCurrentScene($context)) {
            $this->abandon($context);
        }
    }

    /**
     * On the way to the scene pick, with a session under way: the session opening when it is due,
     * the phase opening the first time in the phase, the phase closing instead of the pick when the
     * phase is ending. At the pick a selection with one Scene Type picks it when a session started or
     * the phase opened since the last scene; after a scene it waits for the player, who plays the
     * next scene or ends the session (ADR 0020). A forced Scene Type and an oracle selection wait for
     * the player. Without a session the FlowRun waits at the pick.
     */
    private function reachScenePick(FlowRunContext $context): void
    {
        $this->stage = FlowRunStage::ScenePick;
        if (null === $context->sessionNumber()) {
            return;
        }

        if ($this->sessionOpeningDue) {
            $this->sessionOpeningDue = false;
            $this->autoPickDue = true;
            $this->runHook(Hook::SessionOpening, $context);
        } elseif (!$this->phaseOpened) {
            $this->phaseOpened = true;
            $this->autoPickDue = true;
            $this->runHook(Hook::PhaseOpening, $context);
        } elseif (null !== $this->phaseEnding) {
            $this->closePhase($context);
        } elseif (null === $this->forcedNextSceneType && $this->autoPickDue) {
            $this->autoPick($context);
        }
    }

    private function autoPick(FlowRunContext $context): void
    {
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
        $this->picked = null;
        $this->autoPickDue = false;
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
     * Runs a hook of the current phase from its first step, in its own hook Scene, which starts at
     * the first step that waits for the player (or an Effect naming the scene): condition steps that
     * pass the whole hook leave no Scene, and an empty hook is passed at once. Without a session
     * under way the hook waits for one (see goOn()).
     */
    private function runHook(Hook $hook, FlowRunContext $context): void
    {
        $this->stage = FlowRunStage::Hook;
        $this->hook = $hook;
        $this->sessionNumber = $this->sceneNumber = $this->sceneType = $this->part = $this->stepKey = null;
        $first = $hook->steps($this->phase($context->flow))->steps[0] ?? null;
        if (null === $first) {
            $this->afterHook($context);

            return;
        }

        if (null === $context->sessionNumber()) {
            return;
        }

        $this->stepKey = $first->key;
        $this->settle($context);
    }

    /**
     * Starts the running hook's hook Scene unless it has one.
     */
    private function enterHookScene(FlowRunContext $context): void
    {
        if ($this->hook instanceof Hook && null === $this->sceneNumber) {
            $this->sceneNumber = $context->startHookScene($this->hook, $this->phase($context->flow));
            $this->sessionNumber = $context->sessionNumber();
            $this->picked = null;
        }
    }

    /**
     * Applies a finished step's Effects in order, then moves on: to the new Scene Type's setup when
     * one switched the scene (the step's target is not followed), else as advance() does.
     *
     * @param list<Effect> $effects
     * @param ?string      $answer  the step's answer text, which "{answer}" stands for
     */
    private function finish(Step $step, ?Outcome $outcome, array $effects, ?string $answer, FlowRunContext $context): void
    {
        $switched = false;
        foreach ($effects as $effect) {
            $switched = $this->apply($effect, $answer, $context) || $switched;
        }

        if ($switched) {
            $this->enterPart(ScenePart::Setup, $context);
        } else {
            $this->advance($step, $outcome, $context);
        }
    }

    /**
     * @return bool whether the Effect switched the scene's Scene Type
     */
    private function apply(Effect $effect, ?string $answer, FlowRunContext $context): bool
    {
        if ($effect instanceof TrackerEffect) {
            $context->applyTrackerEffect($effect);
        } elseif ($effect instanceof NextSceneEffect) {
            $this->forceNextSceneType($effect->sceneType);
        } elseif ($effect instanceof EndPhaseEffect) {
            $this->endPhaseAfterScene();
        } elseif ($effect instanceof SceneTitleEffect) {
            // Rendered when it applies, cut to the longest title; an empty title is ignored.
            $this->enterHookScene($context);
            $title = $this->renderedEffectText($effect->title, Scene::MAX_TITLE_LENGTH, $answer, $context);
            if ('' !== $title) {
                $context->renameScene($title);
            }
        } elseif ($effect instanceof CreateNpcEffect || $effect instanceof CreateThreadEffect) {
            $this->createItem($effect, $answer, $context);
        } elseif ($effect instanceof FillFactEffect) {
            $this->fillFact($effect, $answer, $context);
        } elseif ($effect instanceof CloseThreadEffect) {
            $context->closePickedThreads($this->picked);
        } elseif ($effect instanceof SwitchSceneTypeEffect) {
            return $this->switchSceneTypeByEffect($effect->sceneType, $context);
        }

        return false;
    }

    /**
     * Creates an open NPC or Thread in the hook Scene or scene under way: name and agenda rendered
     * and cut like a scene title; a name that comes out empty is "New <label of the first Tag>", or
     * "New NPC" / "New Thread". What is created is "{picked}" (and a created NPC joins the scene's
     * cast); when the campaign holds the most items of the kind, nothing is created and "{picked}"
     * stays.
     */
    private function createItem(CreateNpcEffect|CreateThreadEffect $effect, ?string $answer, FlowRunContext $context): void
    {
        $this->enterHookScene($context);
        $isNpc = $effect instanceof CreateNpcEffect;
        $name = $this->renderedEffectText($effect->name ?? '', $isNpc ? Npc::MAX_NAME_LENGTH : Thread::MAX_NAME_LENGTH, $answer, $context);
        if ('' === $name) {
            $name = 'New '.(isset($effect->tags[0]) ? ($context->release->tag($effect->tags[0])->label ?? $effect->tags[0]) : ($isNpc ? 'NPC' : 'Thread'));
        }

        $created = $effect instanceof CreateNpcEffect
            ? $context->createNpc($name, null === $effect->agenda ? null : $this->renderedEffectText($effect->agenda, Npc::MAX_AGENDA_LENGTH, $answer, $context), $effect->tags)
            : $context->createThread($name, $effect->tags);
        if ($created instanceof PickedItems) {
            $this->picked = $created;
            $context->joinSceneCast($created);
        }
    }

    /**
     * Fills a Fact slot, which is no scene's business: a text slot takes the rendered text (the
     * answer when omitted), trimmed and cut to the longest fact text; an NPC or Thread slot takes
     * the first item of its kind in "{picked}". An empty text, a missing answer when no text is given,
     * or nothing of the kind picked, changes nothing; a campaign at its most facts adds none.
     */
    private function fillFact(FillFactEffect $effect, ?string $answer, FlowRunContext $context): void
    {
        $type = ($context->release->factSlot($effect->slot) ?? throw new \LogicException(\sprintf('The pinned release has no Fact slot "%s".', $effect->slot)))->type;
        if (FactSlotType::Text === $type) {
            // The default text is the answer: without one there is nothing to fill with, not the "—" placeholder.
            if (null === $effect->text && '' === trim($answer ?? '')) {
                return;
            }

            $text = $this->renderedEffectText($effect->text ?? '{answer}', Fact::MAX_TEXT_LENGTH, $answer, $context);
            if ('' !== $text) {
                $context->fillFact($effect->slot, $text, null);
            }

            return;
        }

        // A picked Character fits no slot (they hold NPCs and Threads): like any other kind mismatch, nothing is linked.
        $slotKind = match ($type) {
            FactSlotType::Npc => ItemKind::Npc,
            FactSlotType::Thread => ItemKind::Thread,
        };
        $id = $this->picked instanceof PickedItems && $this->picked->kind === $slotKind ? ($this->picked->ids[0] ?? null) : null;
        if (null !== $id) {
            $context->fillFact($effect->slot, null, FactSlotType::Npc === $type ? FactLink::npc(NpcId::fromString($id)) : FactLink::thread(ThreadId::fromString($id)));
        }
    }

    /**
     * The Fact slots any Effect of the step may fill, in the release's slot order.
     *
     * @return list<FactSlot>
     */
    private function fills(Step $step, GameSystemSnapshot $release): array
    {
        $keys = array_map(
            static fn (FillFactEffect $effect): string => $effect->slot,
            array_filter(StepEffects::of($step, $release->tableEffects(...)), static fn (Effect $effect): bool => $effect instanceof FillFactEffect),
        );

        return array_values(array_filter($release->factSlots(), static fn (FactSlot $slot): bool => \in_array($slot->key, $keys, true)));
    }

    /**
     * An Effect's text with its placeholders rendered, trimmed and cut to the longest the field
     * takes.
     */
    private function renderedEffectText(string $text, int $max, ?string $answer, FlowRunContext $context): string
    {
        $rendered = new Placeholders($context->trackerValues(), $this->answers, $context->factValues(), $answer, $context->pickedNames($this->picked))->render($text);

        return trim(mb_substr(trim($rendered), 0, $max));
    }

    /**
     * The first switch through Effects in a scene changes its Scene Type in place (recorded as a
     * hand switch is); later ones, and any in a hook (a hook Scene has no Scene Type), are ignored
     * and recorded. An ignored switch does not start the hook Scene: it is recorded against the
     * Scene the hook has started, none when no step of the hook has waited yet.
     */
    private function switchSceneTypeByEffect(string $sceneTypeKey, FlowRunContext $context): bool
    {
        if ($this->switchCount > 0 || $this->hook instanceof Hook) {
            $scene = $this->hook instanceof Hook ? $this->sceneNumber : $context->sceneNumber();
            $this->history[] = FlowRunHistoryEntry::sceneTypeSwitchIgnored($context->at, $scene, $sceneTypeKey);

            return false;
        }

        $scene = (int) $context->sceneNumber();
        $from = $context->switchSceneType($context->sceneType($sceneTypeKey));
        $this->history[] = FlowRunHistoryEntry::sceneTypeSwitch($context->at, $scene, $from, $sceneTypeKey);
        $this->sceneType = $sceneTypeKey;
        ++$this->switchCount;

        return true;
    }

    /**
     * The Outcome of the first band whose "upTo" (a literal, or a Tracker's current value) the value
     * does not pass; the band without "upTo" catches the rest.
     *
     * @param list<Band> $bands
     */
    private function band(array $bands, int $value, FlowRunContext $context): ?Outcome
    {
        foreach ($bands as $band) {
            $upTo = $band->upTo instanceof TrackerReference ? $context->trackerValue($band->upTo->tracker) : $band->upTo;
            if (null === $upTo || $value <= $upTo) {
                return $band->outcome;
            }
        }

        return null;
    }

    /**
     * Moves on from a finished step: to the outcome's "next", else the step's, else the following
     * step; "end" or no following step ends the part or the hook.
     */
    private function advance(Step $step, ?Outcome $outcome, FlowRunContext $context): void
    {
        $steps = $this->stepList($context->release, $context->flow) ?? throw new \LogicException('A step runs in a part or a hook.');
        $target = $this->target($steps, $step, $outcome);
        if (!$target instanceof Step) {
            $this->hook instanceof Hook ? $this->afterHook($context) : $this->leavePart($context);

            return;
        }

        $this->stepKey = $target->key;
        $this->settle($context);
    }

    /**
     * A condition step never waits: it takes the band of its Tracker's current value (or of its
     * item count), applies its Effects and advances on its own, with no answer. A step that waits
     * in a hook starts its hook Scene.
     */
    private function settle(FlowRunContext $context): void
    {
        $step = $this->currentStep($context->release, $context->flow);
        if ($step instanceof ConditionStep) {
            $value = $step->count instanceof ItemCount ? $context->openItemCount($step->count) : $context->trackerValue((string) $step->tracker);
            $outcome = $this->band($step->bands, $value, $context);
            $this->finish($step, $outcome, [...$step->effects, ...$outcome->effects ?? []], null, $context);
        } else {
            $this->enterHookScene($context);
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

    /**
     * After a scene: the phase closing when the phase is finished, else the world turn, then the
     * scene pick.
     */
    private function afterScene(FlowRunContext $context): void
    {
        $this->stage = FlowRunStage::ScenePick;
        $this->sessionNumber = $this->sceneNumber = $this->sceneType = $this->part = $this->stepKey = null;
        $this->switchCount = 0;
        $this->picked = null;
        if ($this->phaseFinished($context->flow)) {
            $this->closePhase($context);
        } else {
            $this->runHook(Hook::WorldTurn, $context);
        }
    }

    /**
     * After a hook: the next phase after the phase closing; else, the session ended after its
     * closing (not when abandoned), on the way to the scene pick.
     */
    private function afterHook(FlowRunContext $context, bool $abandoned = false): void
    {
        $hook = $this->hook;
        $this->stage = FlowRunStage::ScenePick;
        $this->hook = null;
        $this->sessionNumber = $this->sceneNumber = $this->stepKey = null;
        $this->picked = null;
        if (Hook::PhaseClosing === $hook) {
            $this->finishPhase($context);

            return;
        }

        if (Hook::SessionClosing === $hook && !$abandoned) {
            $context->endSession();
        }

        $this->reachScenePick($context);
    }

    /**
     * Leaves the guided scene or hook Scene unfinished (recorded) and goes on as after it.
     */
    private function abandon(FlowRunContext $context): void
    {
        $this->history[] = FlowRunHistoryEntry::sceneAbandoned($context->at, (int) $this->sessionNumber, (int) $this->sceneNumber);
        $this->hook instanceof Hook ? $this->afterHook($context, true) : $this->afterScene($context);
    }

    /**
     * Runs the phase closing; the phase ends after it ("finished" when no Effect or "Move on" ended
     * it, that is a once phase that played its scenes).
     */
    private function closePhase(FlowRunContext $context): void
    {
        $this->phaseEnding ??= 'finished';
        $this->runHook(Hook::PhaseClosing, $context);
    }

    private function finishPhase(FlowRunContext $context): void
    {
        $this->history[] = FlowRunHistoryEntry::phaseEnded($context->at, $this->phase($context->flow)->key, $this->phaseEnding ?? 'finished');
        ++$this->phaseIndex;
        $this->sequencePosition = $this->scenesPlayed = 0;
        $this->phaseEnding = null;
        $this->phaseOpened = false;
        if (!$context->flow->phaseAt($this->phaseIndex) instanceof Phase) {
            $this->status = FlowRunStatus::Completed;
            $this->history[] = FlowRunHistoryEntry::completed($context->at);

            return;
        }

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
     * are passed), across parts, past the scene or hook to the next hook, pick or phase.
     *
     * @param bool $sessionOpening whether the session opening comes before the scene pick
     */
    private function nextLabel(?Step $step, GameSystemSnapshot $release, Flow $flow, Placeholders $placeholders, bool $sessionOpening): string
    {
        if ($this->hook instanceof Hook) {
            $steps = $this->hook->steps($this->phase($flow));
            $next = $this->effective($steps, $step instanceof Step ? $this->target($steps, $step, null) : ($steps->steps[0] ?? null));

            return $next instanceof Step ? $this->hook->label().': '.$placeholders->render($next->title) : $this->labelAfterHook($this->hook, $release, $flow, $placeholders);
        }

        if (!$this->part instanceof ScenePart) {
            return $this->labelTowardsPick($sessionOpening, $release, $flow, $placeholders);
        }

        $part = $this->part;
        $steps = $this->steps($part, $release, $flow);
        $next = $step instanceof Step ? $this->effective($steps, $this->target($steps, $step, null)) : null;
        while (!$next instanceof Step) {
            $part = $part->next();
            if (!$part instanceof ScenePart) {
                return $this->labelAfterScene($release, $flow, $placeholders);
            }

            if (ScenePart::Open === $part) {
                return $part->label();
            }

            $steps = $this->steps($part, $release, $flow);
            $next = $this->effective($steps, $steps->steps[0] ?? null);
        }

        return $part->label().': '.$placeholders->render($next->title);
    }

    /**
     * What a pick step shows: its Tags with their release labels (a key the release no longer has
     * cannot happen, the translator checks them) and the candidates now.
     */
    private function pickView(PickStep $step, GameSystemSnapshot $release, PickCandidates $candidates): PickView
    {
        $tags = array_map(static fn (string $key): Tag => $release->tag($key) ?? throw new \LogicException(\sprintf('Pick step "%s" names Tag "%s", which the release does not have.', $step->key, $key)), $step->tags);

        return new PickView($step->of, $step->mode, $step->min, $step->max, $tags, $candidates->items);
    }

    private function rendered(?string $text, Placeholders $placeholders): ?string
    {
        return null === $text ? null : $placeholders->render($text);
    }

    private function labelAfterScene(GameSystemSnapshot $release, Flow $flow, Placeholders $placeholders): string
    {
        if (!$this->phaseFinished($flow)) {
            return $this->hookLabel(Hook::WorldTurn, $flow, $placeholders) ?? $this->pickLabel($release, $flow);
        }

        return $this->labelPhaseClosing($flow, $placeholders);
    }

    private function labelAfterHook(Hook $hook, GameSystemSnapshot $release, Flow $flow, Placeholders $placeholders): string
    {
        return match ($hook) {
            Hook::PhaseClosing => $this->labelPhaseComplete($flow),
            Hook::SessionClosing => 'Session ends',
            default => $this->labelTowardsPick(false, $release, $flow, $placeholders),
        };
    }

    /**
     * On the way to the scene pick: the session opening (when it comes first) and the phase opening
     * (the first time in the phase) when they have steps, the phase closing when the phase is
     * ending, else the pick.
     */
    private function labelTowardsPick(bool $sessionOpening, GameSystemSnapshot $release, Flow $flow, Placeholders $placeholders): string
    {
        $label = ($sessionOpening ? $this->hookLabel(Hook::SessionOpening, $flow, $placeholders) : null)
            ?? ($this->phaseOpened ? null : $this->hookLabel(Hook::PhaseOpening, $flow, $placeholders));
        if (null !== $label) {
            return $label;
        }

        return null === $this->phaseEnding ? $this->pickLabel($release, $flow) : $this->labelPhaseClosing($flow, $placeholders);
    }

    private function labelPhaseClosing(Flow $flow, Placeholders $placeholders): string
    {
        return $this->hookLabel(Hook::PhaseClosing, $flow, $placeholders) ?? $this->labelPhaseComplete($flow);
    }

    private function labelPhaseComplete(Flow $flow): string
    {
        $next = $flow->phaseAt($this->phaseIndex + 1);

        return $next instanceof Phase ? \sprintf('%s complete → %s', $this->phase($flow)->name, $next->name) : 'Flow complete';
    }

    /**
     * A hook of the current phase named by its first step a player meets; null when it has none.
     */
    private function hookLabel(Hook $hook, Flow $flow, Placeholders $placeholders): ?string
    {
        $steps = $hook->steps($this->phase($flow));
        $first = $this->effective($steps, $steps->steps[0] ?? null);

        return $first instanceof Step ? $hook->label().': '.$placeholders->render($first->title) : null;
    }

    /**
     * The scene pick named: the one Scene Type offered (the next of a sequence, the forced or the
     * only one), else the choice or the table to roll.
     */
    private function pickLabel(GameSystemSnapshot $release, Flow $flow): string
    {
        $selection = $this->phase($flow)->selection;
        $offered = $this->offered($release, $flow);
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
        return null === $this->stepKey ? null : $this->stepList($release, $flow)?->step($this->stepKey);
    }

    /**
     * The steps of the running hook or the current part; null at the scene pick.
     */
    private function stepList(GameSystemSnapshot $release, Flow $flow): ?StepList
    {
        return match (true) {
            $this->hook instanceof Hook => $this->hook->steps($this->phase($flow)),
            $this->part instanceof ScenePart => $this->steps($this->part, $release, $flow),
            default => null,
        };
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
