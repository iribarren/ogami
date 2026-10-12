<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\Campaign\Lists\Npc;
use App\Play\Domain\Campaign\Lists\Thread;
use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
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
        yield 'a stranger appears' => [25, 'instead'];
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
        self::assertSame(['sceneOpening', 'instead'], self::position($campaign));
    }

    #[Test]
    public function aSettledLooseEndCanCloseItsThread(): void
    {
        $campaign = $this->interruptedScene();
        $campaign->completeFlowStep('interrupt', StepResult::table($this->focusRoll(50)), $this->release, self::at());

        $thread = $campaign->threads()[0];
        $campaign->completeFlowStep('loose', StepResult::pick(ItemKind::Thread, [new \App\Play\Domain\Campaign\FlowRun\PickedItem($thread->id()->toString(), $thread->name())]), $this->release, self::at());
        self::assertSame(['sceneOpening', 'close'], self::position($campaign));
        $campaign->completeFlowStep('close', StepResult::choice('yes'), $this->release, self::at());

        self::assertSame(['sceneOpening', 'instead'], self::position($campaign));
        self::assertFalse($campaign->threads()[0]->isOpen());
    }

    /**
     * A campaign in its first adventure scene, with a Thread and an NPC from the premise, at the
     * "interrupt" step (the scene check was low and the twist an interruption).
     */
    private function interruptedScene(): Campaign
    {
        $campaign = self::guided($this->release, 'mythic');
        $campaign->startSession(self::at(), $this->release);
        $campaign->completeFlowStep('premise', self::prompt('A caravan vanishes'), $this->release, self::at());
        $campaign->completeFlowStep('goal', self::prompt('Find the caravan'), $this->release, self::at());
        $campaign->completeFlowStep('involved', self::prompt('Mara'), $this->release, self::at());
        $campaign->endFlowScene(1, $this->release, self::at());

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
