<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CompleteFlowStep;
use App\Play\Application\CompleteFlowStepHandler;
use App\Play\Domain\Campaign\FlowRun\InvalidStepResult;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\ThreadId;
use App\Play\Infrastructure\GameSystem\GameSystemReleaseTranslator;
use App\Tests\Support\Play\GuidedReleases;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The pick step through the handler, on the guided release with Tags "rival" and "ally" and two
 * more Scene Types played from the "tour" Flow: "rivals" (a random pick of one or two rival NPCs,
 * then the prompt "then") and "leads" (a choose pick of one Thread, then "then"). "campaign-5"
 * holds the NPCs Ana (weight 1) and Bea (weight 3, rival) and the Threads t1 and t2, and plays
 * "rivals"; "campaign-6" plays "leads".
 */
#[CoversClass(CompleteFlowStep::class)]
#[CoversClass(CompleteFlowStepHandler::class)]
final class CompleteFlowStepPickTest extends FlowRunHandlerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $content = GuidedReleases::content();
        /** @var list<array<string, mixed>> $sceneTypes */
        $sceneTypes = $content['sceneTypes'];
        $pickThen = static fn (array $pick): array => [$pick, ['key' => 'then', 'kind' => 'prompt', 'title' => 'Then {picked}']];
        $sceneTypes[] = ['key' => 'rivals', 'name' => 'Rivals', 'purpose' => 'Rivals.', 'oracles' => [], 'setup' => $pickThen(['key' => 'rival', 'kind' => 'pick', 'title' => 'Which rival?', 'of' => 'npc', 'tags' => ['rival'], 'mode' => 'random', 'count' => ['min' => 1, 'max' => 2]]), 'play' => [], 'closing' => []];
        $sceneTypes[] = ['key' => 'leads', 'name' => 'Leads', 'purpose' => 'Leads.', 'oracles' => [], 'setup' => $pickThen(['key' => 'lead', 'kind' => 'pick', 'title' => 'Which lead?', 'of' => 'thread', 'mode' => 'choose']), 'play' => [], 'closing' => []];
        $content = ReleaseArrays::with($content, 'flows.1.phases.0.selection.sceneTypes', ['tour', 'quiet', 'rivals', 'leads']);
        /** @var array<string, mixed> $content */
        $content = [...$content, 'sceneTypes' => $sceneTypes, 'tags' => [['key' => 'rival', 'label' => 'Rival'], ['key' => 'ally', 'label' => 'Ally']]];
        $this->release = new GameSystemReleaseTranslator()->translate(ReleaseViews::of($content));
        $this->releases->add($this->release);

        foreach (['campaign-5' => 'rivals', 'campaign-6' => 'leads'] as $id => $sceneType) {
            $campaign = $this->guided($id, 'tour');
            $campaign->addNpc(NpcId::fromString('ana'), 'Ana', null, ['ally'], [], 1, $this->release);
            $campaign->addNpc(NpcId::fromString('bea'), 'Bea', null, ['rival'], [], 3, $this->release);
            $campaign->addNpc(NpcId::fromString('cid'), 'Cid', null, ['rival'], [], 1, $this->release);
            $campaign->addThread(ThreadId::fromString('t1'), 'Rob the vault', [], [], 1, $this->release);
            $campaign->addThread(ThreadId::fromString('t2'), 'Find the ledger', [], [], 1, $this->release);
            $campaign->pickSceneType($sceneType, $this->release, self::at('09:10'));
            $this->campaigns->add($campaign);
        }
    }

    #[Test]
    public function aRandomPickIsDrawnByTheServerAndRecordedWithTheNamesAtPickTime(): void
    {
        // Bea (weight 3) of rivals Bea and Cid (4 in total): a 3 is Bea; then Cid alone.
        $this->complete(self::command('rival'), 3, 1);

        $entry = $this->onlyEntryOf('campaign-5');
        self::assertSame(['kind' => 'pick', 'of' => 'npc', 'tags' => ['rival'], 'picked' => [['id' => 'bea', 'name' => 'Bea'], ['id' => 'cid', 'name' => 'Cid']]], $entry->content()->toArray());
        self::assertSame(['key' => 'rival', 'title' => 'Which rival?', 'prompt' => null], $entry->flowStep()?->toArray());
        $flowRun = $this->stored('campaign-5')->flowRun();
        self::assertSame([['rival' => 'Bea, Cid'], 'then', ['npc', ['bea', 'cid']]], [$flowRun?->answers(), $flowRun?->stepKey(), [$flowRun?->picked()?->kind->value, $flowRun?->picked()?->ids]]);
        self::assertSame('Then Bea, Cid', $this->stored('campaign-5')->flowRunView($this->release)?->step?->title);
    }

    #[Test]
    public function aRandomPickWithoutCandidatesRecordsNone(): void
    {
        $campaign = $this->stored('campaign-5');
        $campaign->closeNpc(NpcId::fromString('bea'));
        $campaign->closeNpc(NpcId::fromString('cid'));
        $this->campaigns->save($campaign);

        $this->complete(self::command('rival'));

        self::assertSame(['kind' => 'pick', 'of' => 'npc', 'tags' => ['rival'], 'picked' => []], $this->onlyEntryOf('campaign-5')->content()->toArray());
        self::assertSame(['rival' => ''], $this->stored('campaign-5')->flowRun()?->answers());
    }

    #[Test]
    public function aChosenPickRecordsTheCandidatesTheIdsNameAndTheirOrder(): void
    {
        $this->complete(self::command('lead', pickedIds: ['t2'], campaignId: 'campaign-6'));

        self::assertSame(['kind' => 'pick', 'of' => 'thread', 'tags' => [], 'picked' => [['id' => 't2', 'name' => 'Find the ledger']]], $this->onlyEntryOf('campaign-6')->content()->toArray());
        self::assertSame('Then Find the ledger', $this->stored('campaign-6')->flowRunView($this->release)?->step?->title);
    }

    /**
     * @return iterable<string, array{CompleteFlowStep, string}>
     */
    public static function refusedPicks(): iterable
    {
        yield 'a random pick with ids' => [self::command('rival', pickedIds: ['bea']), 'Step "rival" is a pick step: it takes no "pickedIds".'];
        yield 'a chosen pick without ids' => [self::command('lead', campaignId: 'campaign-6'), 'Step "lead" is a pick step: it needs "pickedIds".'];
        yield 'a chosen pick of an unknown id' => [self::command('lead', pickedIds: ['t9'], campaignId: 'campaign-6'), 'Pick step "lead" has no candidate "t9".'];
        yield 'a chosen pick of an id twice' => [self::command('lead', pickedIds: ['t1', 't1'], campaignId: 'campaign-6'), 'Pick step "lead" was given "t1" twice.'];
        yield 'a chosen pick of too many' => [self::command('lead', pickedIds: ['t1', 't2'], campaignId: 'campaign-6'), 'Pick step "lead" takes 1 item, got 2.'];
        yield 'a chosen pick of none' => [self::command('lead', pickedIds: [], campaignId: 'campaign-6'), 'Pick step "lead" takes 1 item, got 0.'];
        yield 'a chosen pick with text' => [self::command('lead', text: 'x', pickedIds: ['t1'], campaignId: 'campaign-6'), 'Step "lead" is a pick step: it takes no "text".'];
    }

    #[Test]
    #[DataProvider('refusedPicks')]
    public function aRefusedPickRecordsNothingAndPicksNothing(CompleteFlowStep $command, string $message): void
    {
        $before = $this->stored($command->campaignId);

        try {
            $this->complete($command, 1, 1);
            self::fail('A refused pick completed the step.');
        } catch (InvalidStepResult $exception) {
            self::assertSame($message, $exception->getMessage());
        }

        self::assertSame([], $this->journalOf($command->campaignId));
        self::assertEquals($before, $this->stored($command->campaignId));
    }

    /**
     * @param list<string>|null $pickedIds
     */
    private static function command(string $stepKey, ?string $text = null, ?array $pickedIds = null, string $campaignId = 'campaign-5'): CompleteFlowStep
    {
        return new CompleteFlowStep($campaignId, 'user-1', 'entry-1', $stepKey, $text, null, null, null, $pickedIds);
    }

    private function complete(CompleteFlowStep $command, int ...$rolls): void
    {
        new CompleteFlowStepHandler($this->journal, $this->campaigns, new ScriptedRandomNumberGenerator(...$rolls), $this->clock)($command);
    }
}
