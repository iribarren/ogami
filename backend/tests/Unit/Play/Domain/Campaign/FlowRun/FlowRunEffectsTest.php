<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunContext;
use App\Play\Domain\Campaign\FlowRun\FlowRunView;
use App\Play\Domain\Campaign\FlowRun\PickedItem;
use App\Play\Domain\Campaign\FlowRun\PickedItems;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\Campaign\Hook;
use App\Play\Domain\Campaign\Lists\Npc;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\Thread;
use App\Play\Domain\Campaign\Lists\ThreadId;
use App\Play\Domain\Campaign\SceneKind;
use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * The createNpc, createThread and closeThread Effects while a FlowRun plays, and the NPC part of
 * the Scene cast, on a test release with Tags "rival" and "ally", a "heat" counter (2) and Scene
 * Types that apply the Effects after a prompt or a pick. The world turn is replaced per test.
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(FlowRunContext::class)]
#[CoversClass(Campaign::class)]
final class FlowRunEffectsTest extends FlowRunTestCase
{
    /** @var ?list<array<string, mixed>> the world turn of the release under test */
    private ?array $worldTurn = null;

    #[Test]
    public function createNpcCreatesAnOpenNpcFromRenderedTextWithTheEffectsTagsAndItBecomesPicked(): void
    {
        $campaign = $this->inScene('spawn', lists: false);

        $campaign->completeFlowStep('ask', self::prompt('Vega'), $this->snapshot(), self::at());

        $npc = $this->onlyNpc($campaign);
        self::assertSame(['Ghost of Vega', 'Heat 2: Vega', ['rival'], [], 1, true], [$npc->name(), $npc->agenda(), $npc->tags(), $npc->freeTags(), $npc->weight(), $npc->isOpen()]);
        self::assertSame('Picked: Ghost of Vega', $this->view($campaign)->step?->title);
        self::assertEquals(new PickedItems(ItemKind::Npc, [$npc->id()->toString()]), self::flowRunOf($campaign)->picked());
        self::assertSame([$npc->id()->toString()], $this->cast($campaign), 'A created NPC joins the current Scene\'s cast.');
    }

    #[Test]
    public function createThreadCreatesAnOpenThreadAndItReplacesWhatWasPicked(): void
    {
        $campaign = $this->inScene('thread', lists: false);
        $campaign->addNpc(NpcId::fromString('ana'), 'Ana', null, [], [], 1, $this->snapshot());
        $campaign->completeFlowStep('who', StepResult::pick(ItemKind::Npc, [new PickedItem('ana', 'Ana')]), $this->snapshot(), self::at());

        $campaign->completeFlowStep('ask', self::prompt('the ledger'), $this->snapshot(), self::at());

        $thread = $this->onlyThread($campaign);
        self::assertSame(['Find the ledger', ['ally'], [], 1, true], [$thread->name(), $thread->tags(), $thread->freeTags(), $thread->weight(), $thread->isOpen()]);
        self::assertSame('Picked: Find the ledger', $this->view($campaign)->step?->title);
        self::assertSame(['ana'], $this->cast($campaign), 'A Thread joins no cast.');
    }

    #[Test]
    public function aMissingNameBecomesNewWithTheFirstTagsLabelOrTheKind(): void
    {
        foreach ([['anon', 'New NPC', 'New Thread'], ['tagged', 'New Rival', 'New Ally']] as [$sceneType, $npcName, $threadName]) {
            $campaign = $this->inScene($sceneType, lists: false);

            $campaign->completeFlowStep('make', self::prompt('ok'), $this->snapshot(), self::at());

            self::assertSame([$npcName, $threadName], [$this->onlyNpc($campaign)->name(), $this->onlyThread($campaign)->name()]);
            self::assertNull($this->onlyNpc($campaign)->agenda(), 'No agenda stays empty.');
        }
    }

    #[Test]
    public function aNameThatRendersEmptyGetsTheDefaultToo(): void
    {
        $campaign = $this->inScene('blank', lists: false);

        $campaign->completeFlowStep('make', self::prompt('ok'), $this->snapshot(), self::at());

        self::assertSame(['New NPC', null], [$this->onlyNpc($campaign)->name(), $this->onlyNpc($campaign)->agenda()]);
    }

    #[Test]
    public function nameAndAgendaAreCutToTheLongestTheFieldTakes(): void
    {
        $campaign = $this->inScene('long', lists: false);

        $campaign->completeFlowStep('make', self::prompt(str_repeat('n', 250)), $this->snapshot(), self::at());

        $npc = $this->onlyNpc($campaign);
        self::assertSame([100, 2000, 200], [mb_strlen($npc->name()), mb_strlen((string) $npc->agenda()), mb_strlen($this->onlyThread($campaign)->name())]);
    }

    #[Test]
    public function theNameIsTrimmedAgainAfterItIsCut(): void
    {
        $campaign = $this->inScene('long', lists: false);

        $campaign->completeFlowStep('make', self::prompt(str_repeat('n', 99).' tail'), $this->snapshot(), self::at());

        self::assertSame(99, mb_strlen($this->onlyNpc($campaign)->name()));
    }

    #[Test]
    public function whenTheCampaignHoldsTheLimitNothingIsCreatedAndPickedStays(): void
    {
        $campaign = $this->inScene('limit', lists: false);
        foreach (range(1, Campaign::MAX_NPCS) as $n) {
            $campaign->addNpc(NpcId::fromString('npc-'.$n), 'NPC '.$n, null, [], [], 1, $this->snapshot());
        }

        foreach (range(1, Campaign::MAX_THREADS) as $n) {
            $campaign->addThread(ThreadId::fromString('thread-'.$n), 'Thread '.$n, [], [], 1, $this->snapshot());
        }

        $campaign->completeFlowStep('who', StepResult::pick(ItemKind::Npc, [new PickedItem('npc-7', 'NPC 7')]), $this->snapshot(), self::at());
        $campaign->completeFlowStep('make', self::prompt('ok'), $this->snapshot(), self::at());

        self::assertSame([Campaign::MAX_NPCS, Campaign::MAX_THREADS], [\count($campaign->npcs()), \count($campaign->threads())]);
        self::assertSame('Picked: NPC 7', $this->view($campaign)->step?->title);
        self::assertSame(['npc-7'], $this->cast($campaign));
    }

    #[Test]
    public function closeThreadClosesThePickedThreadsAndLeavesClosedOnesAlone(): void
    {
        $campaign = $this->inScene('close');
        $campaign->completeFlowStep('lead', StepResult::pick(ItemKind::Thread, [new PickedItem('t1', 'Rob the vault'), new PickedItem('t2', 'Find the ledger')]), $this->snapshot(), self::at());
        $campaign->closeThread(ThreadId::fromString('t2'));

        $campaign->completeFlowStep('close', self::prompt('ok'), $this->snapshot(), self::at());

        self::assertSame([false, false, true], [$this->thread($campaign, 't1')->isOpen(), $this->thread($campaign, 't2')->isOpen(), $this->thread($campaign, 't3')->isOpen()]);
    }

    #[Test]
    public function closeThreadDoesNothingForPickedNpcsOrWhenNothingWasPicked(): void
    {
        $withNpc = $this->inScene('closenpc');
        $withNpc->completeFlowStep('who', StepResult::pick(ItemKind::Npc, [new PickedItem('ana', 'Ana')]), $this->snapshot(), self::at());
        $before = clone $withNpc;
        $withNpc->completeFlowStep('close', self::prompt('ok'), $this->snapshot(), self::at());

        $nothing = $this->inScene('closenone');
        $nothingBefore = clone $nothing;
        $nothing->completeFlowStep('close', self::prompt('ok'), $this->snapshot(), self::at());

        self::assertSame([$before->threads(), true], [$withNpc->threads(), $withNpc->npc(NpcId::fromString('ana'))?->isOpen()]);
        self::assertEquals($nothingBefore->threads(), $nothing->threads());
        self::assertEquals($nothingBefore->npcs(), $nothing->npcs());
    }

    #[Test]
    public function picksJoinTheSceneCastOnceEachInPickOrderAndThreadPicksDoNot(): void
    {
        $campaign = $this->inScene('casting');

        foreach ([['first', 'ana'], ['second', 'bea'], ['third', 'ana']] as [$step, $id]) {
            $campaign->completeFlowStep($step, StepResult::pick(ItemKind::Npc, [new PickedItem($id, ucfirst($id))]), $this->snapshot(), self::at());
        }

        $campaign->completeFlowStep('thread', StepResult::pick(ItemKind::Thread, [new PickedItem('t1', 'Rob the vault')]), $this->snapshot(), self::at());

        self::assertSame(['ana', 'bea'], $this->cast($campaign));
    }

    #[Test]
    public function aPickOfNothingLeavesTheCastAsItWas(): void
    {
        $campaign = $this->inScene('casting');
        $campaign->completeFlowStep('first', StepResult::pick(ItemKind::Npc, [new PickedItem('bea', 'Bea')]), $this->snapshot(), self::at());

        $campaign->closeNpc(NpcId::fromString('ana'));
        $campaign->closeNpc(NpcId::fromString('bea'));
        $campaign->completeFlowStep('second', StepResult::pick(ItemKind::Npc, []), $this->snapshot(), self::at());

        self::assertSame(['bea'], $this->cast($campaign));
    }

    #[Test]
    public function aNewSceneStartsWithAnEmptyCastAndKeepsThePastOnes(): void
    {
        $campaign = $this->inScene('casting');
        $campaign->completeFlowStep('first', StepResult::pick(ItemKind::Npc, [new PickedItem('ana', 'Ana')]), $this->snapshot(), self::at());
        $campaign->completeFlowStep('second', StepResult::pick(ItemKind::Npc, [new PickedItem('bea', 'Bea')]), $this->snapshot(), self::at());
        $campaign->completeFlowStep('third', StepResult::pick(ItemKind::Npc, [new PickedItem('bea', 'Bea')]), $this->snapshot(), self::at());
        $campaign->completeFlowStep('thread', StepResult::pick(ItemKind::Thread, [new PickedItem('t1', 'Rob the vault')]), $this->snapshot(), self::at());
        $campaign->endFlowScene(1, $this->snapshot(), self::at());

        $campaign->pickSceneType('quiet', $this->snapshot(), self::at());

        self::assertSame([], $this->cast($campaign));
        self::assertSame(['ana', 'bea'], array_map(static fn (NpcId $id): string => $id->toString(), $campaign->currentSession()?->scenes()[0]->cast() ?? []));
    }

    #[Test]
    public function createNpcInAHookBeforeItsSceneExistsStartsTheHookSceneAndCastsTheNpc(): void
    {
        $campaign = $this->atTheWorldTurn([
            ['key' => 'check', 'kind' => 'condition', 'title' => 'Anyone?', 'count' => ['of' => 'npc'], 'bands' => [['upTo' => 0, 'effects' => [['kind' => 'createNpc', 'name' => 'Stranger', 'agenda' => 'Heat {tracker:heat}', 'tags' => ['rival']]]], ['next' => 'turn']]],
            ['key' => 'turn', 'kind' => 'prompt', 'title' => 'Then {picked}'],
        ]);

        $scene = $campaign->currentScene();
        $npc = $this->onlyNpc($campaign);
        self::assertSame([SceneKind::Hook, Hook::WorldTurn, 2], [$scene?->kind(), $scene?->hook(), $scene?->number()]);
        self::assertSame(['Stranger', 'Heat 2', 'Then Stranger', [$npc->id()->toString()]], [$npc->name(), $npc->agenda(), $this->view($campaign)->step?->title, $this->cast($campaign)]);
        self::assertSame(2, self::flowRunOf($campaign)->scene()[1] ?? null);
    }

    #[Test]
    public function createThreadAndCloseThreadInAHookWorkOnTheHookScene(): void
    {
        $campaign = $this->atTheWorldTurn([
            ['key' => 'check', 'kind' => 'condition', 'title' => 'Any lead?', 'count' => ['of' => 'thread'], 'bands' => [['upTo' => 0, 'effects' => [['kind' => 'createThread', 'name' => 'Lead', 'tags' => ['ally']]]], ['next' => 'who']]],
            ['key' => 'who', 'kind' => 'pick', 'title' => 'Which lead?', 'of' => 'thread', 'mode' => 'choose', 'effects' => [['kind' => 'closeThread']]],
            ['key' => 'end', 'kind' => 'prompt', 'title' => 'Done'],
        ]);
        $thread = $this->onlyThread($campaign);
        self::assertSame(['Lead', true, 'who'], [$thread->name(), $thread->isOpen(), self::flowRunOf($campaign)->stepKey()]);

        $campaign->completeFlowStep('who', StepResult::pick(ItemKind::Thread, [new PickedItem($thread->id()->toString(), 'Lead')]), $this->snapshot(), self::at());

        self::assertSame([false, 'end'], [$this->onlyThread($campaign)->isOpen(), self::flowRunOf($campaign)->stepKey()]);
    }

    #[Test]
    public function aPickInAHookJoinsTheHookScenesCast(): void
    {
        $campaign = $this->atTheWorldTurn([
            ['key' => 'who', 'kind' => 'pick', 'title' => 'Who?', 'of' => 'npc', 'mode' => 'choose'],
            ['key' => 'end', 'kind' => 'prompt', 'title' => 'Done'],
        ], withNpc: true);

        $campaign->completeFlowStep('who', StepResult::pick(ItemKind::Npc, [new PickedItem('ana', 'Ana')]), $this->snapshot(), self::at());

        self::assertSame([SceneKind::Hook, ['ana']], [$campaign->currentScene()?->kind(), $this->cast($campaign)]);
    }

    private function snapshot(): GameSystemSnapshot
    {
        return self::translate($this->content($this->worldTurn));
    }

    /**
     * A guided campaign in the Scene Type: with two NPCs and three Threads (ana, bea; t1, t2, t3),
     * or, to count what Effects create, with none.
     */
    private function inScene(string $sceneType, bool $lists = true): Campaign
    {
        $release = $this->snapshot();
        $campaign = self::guided($release, 'fx');
        $campaign->startSession(self::at(), $release);
        if ($lists) {
            foreach (['ana', 'bea'] as $id) {
                $campaign->addNpc(NpcId::fromString($id), ucfirst($id), null, ['rival'], [], 1, $release);
            }

            foreach (['t1', 't2', 't3'] as $id) {
                $campaign->addThread(ThreadId::fromString($id), 'Thread '.$id, [], [], 1, $release);
            }
        }

        $campaign->pickSceneType($sceneType, $release, self::at());

        return $campaign;
    }

    /**
     * @param list<array<string, mixed>> $worldTurn
     */
    private function atTheWorldTurn(array $worldTurn, bool $withNpc = false): Campaign
    {
        $this->worldTurn = $worldTurn;
        $release = $this->snapshot();
        $campaign = self::guided($release, 'fx');
        $campaign->startSession(self::at(), $release);
        if ($withNpc) {
            $campaign->addNpc(NpcId::fromString('ana'), 'Ana', null, [], [], 1, $release);
        }

        $campaign->pickSceneType('quiet', $release, self::at());
        $campaign->completeFlowStep('quiet', self::prompt('ok'), $release, self::at());
        $campaign->endFlowScene(1, $release, self::at());

        return $campaign;
    }

    private function view(Campaign $campaign): FlowRunView
    {
        return $campaign->flowRunView($this->snapshot()) ?? self::fail('The campaign has no FlowRun.');
    }

    private function onlyNpc(Campaign $campaign): Npc
    {
        self::assertCount(1, $campaign->npcs());

        return $campaign->npcs()[0];
    }

    private function onlyThread(Campaign $campaign): Thread
    {
        self::assertCount(1, $campaign->threads());

        return $campaign->threads()[0];
    }

    private function thread(Campaign $campaign, string $id): Thread
    {
        return $campaign->thread(ThreadId::fromString($id)) ?? self::fail('No Thread '.$id);
    }

    /**
     * @return list<string> the current scene's cast, as ids
     */
    private function cast(Campaign $campaign): array
    {
        return array_map(static fn (NpcId $id): string => $id->toString(), $campaign->currentScene()?->cast() ?? []);
    }

    /**
     * @param ?list<array<string, mixed>> $worldTurn the steps of the world turn, none when null
     *
     * @return array<string, mixed>
     */
    private function content(?array $worldTurn = null): array
    {
        $prompt = static fn (string $key, string $title, array $fields = []): array => ['key' => $key, 'kind' => 'prompt', 'title' => $title] + $fields;
        $pick = static fn (string $key, string $of, array $fields = []): array => ['key' => $key, 'kind' => 'pick', 'title' => $key, 'of' => $of, 'mode' => 'choose'] + $fields;
        $sceneType = static fn (string $key, array $setup): array => ['key' => $key, 'name' => ucfirst($key), 'purpose' => 'A '.$key.' scene.', 'oracles' => [], 'setup' => $setup, 'play' => [], 'closing' => []];
        $longAgenda = str_repeat('{answer}', 14);

        $sceneTypes = [
            $sceneType('spawn', [
                $prompt('ask', 'Who?', ['effects' => [['kind' => 'createNpc', 'name' => 'Ghost of {answer}', 'agenda' => 'Heat {tracker:heat}: {answer}', 'tags' => ['rival']]]]),
                $prompt('show', 'Picked: {picked}'),
            ]),
            $sceneType('thread', [
                $pick('who', 'npc'),
                $prompt('ask', 'What?', ['effects' => [['kind' => 'createThread', 'name' => 'Find {answer}', 'tags' => ['ally']]]]),
                $prompt('show', 'Picked: {picked}'),
            ]),
            $sceneType('anon', [$prompt('make', 'Make', ['effects' => [['kind' => 'createNpc'], ['kind' => 'createThread']]])]),
            $sceneType('tagged', [$prompt('make', 'Make', ['effects' => [['kind' => 'createNpc', 'tags' => ['rival', 'ally']], ['kind' => 'createThread', 'tags' => ['ally', 'rival']]]])]),
            $sceneType('blank', [$prompt('make', 'Make', ['effects' => [['kind' => 'createNpc', 'name' => '{picked}', 'agenda' => '{picked}']]])]),
            $sceneType('long', [$prompt('make', 'Make', ['effects' => [['kind' => 'createNpc', 'name' => '{answer}', 'agenda' => $longAgenda], ['kind' => 'createThread', 'name' => '{answer}']]])]),
            $sceneType('limit', [
                $pick('who', 'npc'),
                $prompt('make', 'Make', ['effects' => [['kind' => 'createNpc', 'name' => 'One more'], ['kind' => 'createThread', 'name' => 'One more']]]),
                $prompt('show', 'Picked: {picked}'),
            ]),
            $sceneType('close', [$pick('lead', 'thread', ['count' => ['min' => 1, 'max' => 2]]), $prompt('close', 'Close', ['effects' => [['kind' => 'closeThread']]])]),
            $sceneType('closenpc', [$pick('who', 'npc'), $prompt('close', 'Close', ['effects' => [['kind' => 'closeThread']]])]),
            $sceneType('closenone', [$prompt('close', 'Close', ['effects' => [['kind' => 'closeThread']]])]),
            $sceneType('casting', [$pick('first', 'npc'), $pick('second', 'npc'), $pick('third', 'npc'), $pick('thread', 'thread')]),
            $sceneType('quiet', [$prompt('quiet', 'Quiet')]),
        ];

        return [
            'schemaVersion' => 2,
            'gameSystem' => ['key' => 'effects', 'name' => 'Effects'],
            'oracles' => ['tables' => [], 'likelihood' => []],
            'trackers' => [['key' => 'heat', 'name' => 'Heat', 'kind' => 'counter', 'min' => 0, 'max' => 5, 'initial' => 2]],
            'factSlots' => [],
            'tags' => [['key' => 'rival', 'label' => 'Rival'], ['key' => 'ally', 'label' => 'Ally']],
            'sceneTypes' => $sceneTypes,
            'flows' => [['key' => 'fx', 'name' => 'Effects', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => ['heat'], 'phases' => [
                ['key' => 'roam', 'name' => 'Roaming', 'mode' => 'loop', 'selection' => ['rule' => 'player', 'sceneTypes' => array_column($sceneTypes, 'key')]]
                + (null === $worldTurn ? [] : ['worldTurn' => $worldTurn]),
            ]]],
            'checks' => [],
        ];
    }
}
