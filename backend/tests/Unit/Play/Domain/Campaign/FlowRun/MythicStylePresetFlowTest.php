<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunStage;
use App\Play\Domain\Campaign\FlowRun\PickedItem;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\Campaign\Lists\Npc;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\Thread;
use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\SceneType;
use App\Randomness\Domain\Oracle\OracleTableResult;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shipped `mythic-style` preset (flow example 3 with the presets' own tables) played through
 * the Campaign: the premise fills the lists, and a scene opening reaches every branch of the random
 * event focus table.
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(Campaign::class)]
final class MythicStylePresetFlowTest extends FlowRunTestCase
{
    /** What the "What happens instead?" step asks when nobody and nothing was picked. */
    private const string NO_PICK_PROMPT = 'Describe what interrupts the expected scene.';

    private GameSystemSnapshot $release;

    protected function setUp(): void
    {
        /** @var array<string, mixed> $content */
        $content = json_decode((string) file_get_contents(__DIR__.'/../../../../../../presets/mythic-style.json'), true, flags: \JSON_THROW_ON_ERROR);
        $this->release = self::translate($content);
    }

    #[Test]
    public function thePremiseCreatesTheFirstThreadAndTheInvolvedNpc(): void
    {
        $campaign = self::guided($this->release, 'mythic');
        $campaign->startSession(self::at(), $this->release);

        $campaign->completeFlowStep('premise', self::prompt('A caravan vanishes'), $this->release, self::at());
        $campaign->completeFlowStep('goal', self::prompt('Find the caravan'), $this->release, self::at());
        $campaign->completeFlowStep('involved', self::prompt('Mara, the quartermaster'), $this->release, self::at());

        self::assertSame(['Find the caravan'], array_map(static fn (Thread $thread): string => $thread->name(), $campaign->threads()));
        self::assertSame(['Mara, the quartermaster'], array_map(static fn (Npc $npc): string => $npc->name(), $campaign->npcs()));
    }

    /**
     * @return iterable<string, array{int, string}> a 1d100 total of the focus table and the step it leads to
     */
    public static function focusBranches(): iterable
    {
        yield 'a rival acts' => [10, 'rival'];
        yield 'a stranger appears' => [25, 'instead-npc'];
        yield 'a lead gets clearer' => [30, 'lead'];
        yield 'a lead goes cold' => [40, 'lead'];
        yield 'a loose end settles' => [50, 'loose'];
        yield 'an ally needs something' => [75, 'ally'];
        yield 'trouble finds you (any other entry)' => [55, 'action'];
        yield 'something far away (any other entry)' => [5, 'action'];
    }

    #[Test]
    #[DataProvider('focusBranches')]
    public function theRandomEventFocusBranchesByEntry(int $total, string $next): void
    {
        $campaign = $this->interruptedScene();

        $campaign->completeFlowStep('interrupt', StepResult::table($this->focusRoll($total)), $this->release, self::at());

        self::assertSame(['sceneOpening', $next], self::position($campaign));
        // Only the stranger entry creates someone by itself.
        self::assertSame(25 === $total ? ['Mara', 'A stranger'] : ['Mara'], array_map(static fn (Npc $npc): string => $npc->name(), $campaign->npcs()));
    }

    #[Test]
    public function anOtherEntryRollsTheMeaningTablesThenAsksWhatHappensInstead(): void
    {
        $campaign = $this->interruptedScene();
        $campaign->completeFlowStep('interrupt', StepResult::table($this->focusRoll(60)), $this->release, self::at());

        $campaign->completeFlowStep('action', StepResult::table($this->release->resolveOracleTable('meaning-action', new ScriptedRandomNumberGenerator(1))), $this->release, self::at());
        $campaign->completeFlowStep('subject', StepResult::table($this->release->resolveOracleTable('meaning-subject', new ScriptedRandomNumberGenerator(1))), $this->release, self::at());
        self::assertSame(['sceneOpening', 'instead'], self::position($campaign));
        // Nobody was picked: the prompt names no one and has no empty placeholder.
        self::assertSame(self::NO_PICK_PROMPT, $campaign->flowRunView($this->release)?->step?->prompt);

        $campaign->completeFlowStep('instead', self::prompt('The bridge is out'), $this->release, self::at());
        self::assertSame(['open', null, 'The bridge is out'], [...self::position($campaign), $campaign->currentScene()?->title()]);
    }

    #[Test]
    public function aRivalPickWithNobodyCreatesANewRival(): void
    {
        $campaign = $this->interruptedScene();
        $campaign->completeFlowStep('interrupt', StepResult::table($this->focusRoll(10)), $this->release, self::at());

        $campaign->completeFlowStep('rival', StepResult::pick(ItemKind::Npc, []), $this->release, self::at());

        self::assertSame(['Mara', 'A new rival'], array_map(static fn (Npc $npc): string => $npc->name(), $campaign->npcs()));
        self::assertSame(['rival'], $campaign->npcs()[1]->tags());
        self::assertSame(['sceneOpening', 'instead-npc'], self::position($campaign));
        self::assertSame('Involve A new rival.', $campaign->flowRunView($this->release)?->step?->prompt);
    }

    #[Test]
    public function aSettledLooseEndCanCloseItsThread(): void
    {
        $campaign = $this->interruptedScene();
        $campaign->completeFlowStep('interrupt', StepResult::table($this->focusRoll(50)), $this->release, self::at());

        $thread = $campaign->threads()[0];
        $campaign->completeFlowStep('loose', StepResult::pick(ItemKind::Thread, [new PickedItem($thread->id()->toString(), $thread->name())]), $this->release, self::at());
        self::assertSame(['sceneOpening', 'close'], self::position($campaign));
        $campaign->completeFlowStep('close', StepResult::choice('yes'), $this->release, self::at());

        self::assertSame(['sceneOpening', 'instead-thread'], self::position($campaign));
        self::assertSame('Tie it to Find the caravan.', $campaign->flowRunView($this->release)?->step?->prompt);
        self::assertFalse($campaign->threads()[0]->isOpen());
    }

    /**
     * @return iterable<string, array{int, string, ?string, string, string}> the focus roll, its pick
     *                                                                       step, the NPC or Thread
     *                                                                       picked (null for nobody),
     *                                                                       then the step it leads to
     *                                                                       and that step's prompt
     */
    public static function pickPaths(): iterable
    {
        yield 'a rival picked' => [10, 'rival', 'Vex', 'instead-npc', 'Involve Vex.'];
        yield 'an ally picked' => [75, 'ally', 'Ana', 'instead-npc', 'Involve Ana.'];
        yield 'no ally to pick' => [75, 'ally', null, 'instead', self::NO_PICK_PROMPT];
        yield 'a lead picked' => [30, 'lead', 'Find the caravan', 'instead-thread', 'Tie it to Find the caravan.'];
        yield 'a cold lead picked' => [40, 'lead', 'Find the caravan', 'instead-thread', 'Tie it to Find the caravan.'];
    }

    #[Test]
    #[DataProvider('pickPaths')]
    public function aPickLeadsToWhatHappensInsteadWordedForWhatWasPicked(int $total, string $step, ?string $picked, string $next, string $prompt): void
    {
        $campaign = $this->interruptedScene();
        if (\in_array($picked, ['Vex', 'Ana'], true)) {
            $campaign->addNpc(NpcId::fromString(mb_strtolower($picked)), $picked, null, ['Vex' === $picked ? 'rival' : 'ally'], [], 1, $this->release);
        }

        $campaign->completeFlowStep('interrupt', StepResult::table($this->focusRoll($total)), $this->release, self::at());
        $kind = \in_array($step, ['rival', 'ally'], true) ? ItemKind::Npc : ItemKind::Thread;
        $items = ItemKind::Npc === $kind
            ? array_map(static fn (Npc $npc): PickedItem => new PickedItem($npc->id()->toString(), $npc->name()), $campaign->npcs())
            : array_map(static fn (Thread $thread): PickedItem => new PickedItem($thread->id()->toString(), $thread->name()), $campaign->threads());
        $campaign->completeFlowStep($step, StepResult::pick($kind, array_values(array_filter($items, static fn (PickedItem $item): bool => $item->name === $picked))), $this->release, self::at());

        self::assertSame(['sceneOpening', $next], self::position($campaign));
        self::assertSame($prompt, $campaign->flowRunView($this->release)?->step?->prompt);
    }

    #[Test]
    public function theAdventurePhaseOpeningPicksTheOnlySceneTypeItself(): void
    {
        $campaign = $this->firstAdventureScene();

        self::assertSame([FlowRunStage::Scene, 'sceneOpening', 'expected', 2], [self::flowRunOf($campaign)->stage(), ...self::position($campaign), $campaign->currentScene()?->number()]);
    }

    #[Test]
    public function theSceneCheckRollsAtOrUnderTheChaosFactorAndTheSceneTypeFitsThePremiseToo(): void
    {
        $campaign = $this->firstAdventureScene();
        $campaign->completeFlowStep('expected', self::prompt('A quiet market'), $this->release, self::at());

        self::assertSame('Roll at or under the chaos factor (5) and the scene changes.', $campaign->flowRunView($this->release)?->step?->tip);
        self::assertSame('Play out one scene.', $this->release->sceneType('scene')?->purpose);
    }

    #[Test]
    public function afterAnAdventureSceneTheFlowRunWaitsAtTheScenePickWhereTheSessionCanEnd(): void
    {
        $campaign = $this->firstAdventureScene();

        $this->playAsExpected($campaign, 2);

        $view = $campaign->flowRunView($this->release);
        self::assertSame([FlowRunStage::ScenePick, null, 2], [self::flowRunOf($campaign)->stage(), self::flowRunOf($campaign)->scene(), \count($campaign->currentSession()?->scenes() ?? [])]);
        self::assertSame([['scene'], true, true, 'Next scene: Scene'], [array_map(static fn (SceneType $sceneType): string => $sceneType->key, $view?->pick->cards ?? []), $view?->canEndSession, $view?->canMoveOn, $view?->next]);

        $campaign->endSession(self::at(), null, $this->release);

        self::assertNull($campaign->currentSession());
        self::assertSame(FlowRunStage::ScenePick, self::flowRunOf($campaign)->stage());
    }

    #[Test]
    public function playThisStartsTheNextSceneFromTheWaitingPick(): void
    {
        $campaign = $this->firstAdventureScene();
        $this->playAsExpected($campaign, 2);

        $campaign->pickSceneType('scene', $this->release, self::at());

        self::assertSame(['sceneOpening', 'expected', 3], [...self::position($campaign), $campaign->currentScene()?->number()]);
    }

    #[Test]
    public function theWaitingPickStaysThroughPauseAndResume(): void
    {
        $campaign = $this->firstAdventureScene();
        $this->playAsExpected($campaign, 2);

        $campaign->pauseGuidance(self::at());
        $campaign->resumeGuidance($this->release, self::at());

        self::assertSame([FlowRunStage::ScenePick, false, 2], [self::flowRunOf($campaign)->stage(), self::flowRunOf($campaign)->autoPickDue(), \count($campaign->currentSession()?->scenes() ?? [])]);
    }

    #[Test]
    public function theNextSessionPicksTheOnlySceneTypeItself(): void
    {
        $campaign = $this->firstAdventureScene();
        $this->playAsExpected($campaign, 2);
        $campaign->endSession(self::at(), null, $this->release);

        $campaign->startSession(self::at(), $this->release);

        self::assertSame(['sceneOpening', 'expected', 2, 1], [...self::position($campaign), $campaign->currentSession()?->number(), $campaign->currentScene()?->number()]);
    }

    /**
     * A campaign in its first adventure scene (scene 2 of session 1), with a Thread and an NPC from
     * the premise, at the "expected" step.
     */
    private function firstAdventureScene(): Campaign
    {
        $campaign = self::guided($this->release, 'mythic');
        $campaign->startSession(self::at(), $this->release);
        $campaign->completeFlowStep('premise', self::prompt('A caravan vanishes'), $this->release, self::at());
        $campaign->completeFlowStep('goal', self::prompt('Find the caravan'), $this->release, self::at());
        $campaign->completeFlowStep('involved', self::prompt('Mara'), $this->release, self::at());
        $campaign->endFlowScene(1, $this->release, self::at());

        return $campaign;
    }

    /**
     * Plays the guided adventure scene as expected (the scene check passes) to its end.
     */
    private function playAsExpected(Campaign $campaign, int $sceneNumber): void
    {
        $campaign->completeFlowStep('expected', self::prompt('A quiet market'), $this->release, self::at());
        $campaign->completeFlowStep('check', self::roll('1d10', 9), $this->release, self::at());
        $campaign->endFlowScene($sceneNumber, $this->release, self::at());
        $campaign->completeFlowStep('control', StepResult::choice('yes'), $this->release, self::at());
        $campaign->completeFlowStep('lists', self::prompt('Mara is worried'), $this->release, self::at());
    }

    /**
     * A campaign in its first adventure scene, with a Thread and an NPC from the premise, at the
     * "interrupt" step (the scene check was low and the twist an interruption).
     */
    private function interruptedScene(): Campaign
    {
        $campaign = $this->firstAdventureScene();

        $campaign->completeFlowStep('expected', self::prompt('A quiet market'), $this->release, self::at());
        $campaign->completeFlowStep('check', self::roll('1d10', 5), $this->release, self::at());
        $campaign->completeFlowStep('twist', self::roll('1d6', 4), $this->release, self::at());
        self::assertSame(['sceneOpening', 'interrupt'], self::position($campaign));

        return $campaign;
    }

    private function focusRoll(int $total): OracleTableResult
    {
        return $this->release->resolveOracleTable('random-event-focus', new ScriptedRandomNumberGenerator($total));
    }
}
