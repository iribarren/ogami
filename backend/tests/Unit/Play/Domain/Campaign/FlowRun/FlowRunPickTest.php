<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunContext;
use App\Play\Domain\Campaign\FlowRun\InvalidStepResult;
use App\Play\Domain\Campaign\FlowRun\PickCandidate;
use App\Play\Domain\Campaign\FlowRun\PickCandidates;
use App\Play\Domain\Campaign\FlowRun\PickedItem;
use App\Play\Domain\Campaign\FlowRun\StepKind;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\ThreadId;
use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * The pick step and "{picked}" while a FlowRun plays, on a test release with Tags "rival" and
 * "ally" and Scene Types "meet" (a random pick of rival NPCs, one or two), "lead" (a choose pick of
 * Threads, one or two), "duo" (a choose pick of exactly two rivals), "twice" (an NPC pick, then a
 * Thread pick), "optional" (a skippable pick) and "quiet" (a prompt showing "{picked}").
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(FlowRunContext::class)]
#[CoversClass(Campaign::class)]
#[CoversClass(StepResult::class)]
#[CoversClass(PickCandidates::class)]
final class FlowRunPickTest extends FlowRunTestCase
{
    private GameSystemSnapshot $release;

    protected function setUp(): void
    {
        $this->release = self::translate($this->content());
    }

    #[Test]
    public function theCandidatesAreTheOpenItemsOfTheKindCarryingAllTheTagsInListOrder(): void
    {
        $campaign = $this->campaign();
        $campaign->addNpc(NpcId::fromString('free'), 'Free', null, [], ['rival'], 1, $this->release);
        $campaign->addNpc(NpcId::fromString('ally'), 'Ally', null, ['ally'], [], 1, $this->release);
        $campaign->addNpc(NpcId::fromString('closed'), 'Closed', null, ['rival'], [], 1, $this->release);
        $campaign->closeNpc(NpcId::fromString('closed'));
        $campaign->addThread(ThreadId::fromString('t'), 'Thread', ['rival'], [], 1, $this->release);

        self::assertEquals([new PickCandidate('ana', 'Ana', 1), new PickCandidate('bea', 'Bea', 3)], $campaign->pickCandidates(ItemKind::Npc, ['rival'])->items);
        self::assertSame(['ana', 'bea', 'free', 'ally'], array_map(static fn (PickCandidate $item): string => $item->id, $campaign->pickCandidates(ItemKind::Npc, [])->items), 'No tags: every open NPC, in creation order.');
    }

    #[Test]
    public function aRandomPickIsPickedNamedAnsweredAndFollowsFound(): void
    {
        $campaign = $this->inScene('meet');

        $campaign->completeFlowStep('who', StepResult::pick(ItemKind::Npc, [new PickedItem('bea', 'Bea'), new PickedItem('ana', 'Ana')]), $this->release, self::at());

        $flowRun = self::flowRunOf($campaign);
        self::assertSame([['setup', 'after'], ['who' => 'Bea, Ana'], 'Then Bea, Ana', 'Met Bea, Ana'], [self::position($campaign), $flowRun->answers(), $this->view($campaign)->step?->title, $campaign->currentScene()?->title()]);
        self::assertSame(StepKind::Pick, StepResult::pick(ItemKind::Npc, [])->kind);
    }

    #[Test]
    public function aPickOfNothingFollowsNoneAnswersNothingAndLeavesNothingPicked(): void
    {
        $campaign = $this->campaign();
        $campaign->closeNpc(NpcId::fromString('ana'));
        $campaign->closeNpc(NpcId::fromString('bea'));
        $campaign->pickSceneType('meet', $this->release, self::at());

        $campaign->completeFlowStep('who', StepResult::pick(ItemKind::Npc, []), $this->release, self::at());

        self::assertSame([['setup', 'nobody'], ['who' => ''], 'Nobody: []'], [self::position($campaign), self::flowRunOf($campaign)->answers(), $this->view($campaign)->step?->title]);
    }

    #[Test]
    public function aChosenPickTakesTheIdsOfCandidatesBetweenMinAndMax(): void
    {
        $campaign = $this->inScene('lead');

        $campaign->completeFlowStep('lead', StepResult::pick(ItemKind::Thread, [new PickedItem('t2', 'Find the ledger')]), $this->release, self::at());

        self::assertSame([['setup', 'then'], 'Leads: Find the ledger'], [self::position($campaign), $this->view($campaign)->step?->title]);
    }

    #[Test]
    public function aPickWithFewerCandidatesThanTheMinimumCompletesAsNone(): void
    {
        $campaign = $this->inScene('duo');

        $campaign->completeFlowStep('pair', StepResult::pick(ItemKind::Npc, []), $this->release, self::at());

        self::assertSame([['setup', 'alone'], ['pair' => '']], [self::position($campaign), self::flowRunOf($campaign)->answers()]);
    }

    #[Test]
    public function aPickThatBreaksTheRulesChangesNothing(): void
    {
        $campaign = $this->inScene('meet');
        $before = clone $campaign;

        foreach ([
            [StepResult::pick(ItemKind::Npc, [new PickedItem('zed', 'Zed')]), 'Pick step "who" has no candidate "zed".'],
            [StepResult::pick(ItemKind::Npc, [new PickedItem('ana', 'Ana'), new PickedItem('ana', 'Ana')]), 'Pick step "who" was given "ana" twice.'],
            [StepResult::pick(ItemKind::Npc, [new PickedItem('ana', 'Ana'), new PickedItem('bea', 'Bea'), new PickedItem('cid', 'Cid')]), 'Pick step "who" has no candidate "cid".'],
            [StepResult::pick(ItemKind::Npc, []), 'Pick step "who" takes 1 to 2 items, got 0.'],
            [StepResult::pick(ItemKind::Thread, [new PickedItem('t1', 'Rob the vault')]), 'Step "who" picks npcs.'],
            [self::prompt('Ana'), 'Step "who" is a pick step: a prompt result does not complete it.'],
        ] as [$result, $message]) {
            try {
                $campaign->completeFlowStep('who', $result, $this->release, self::at());
                self::fail('The pick was accepted.');
            } catch (InvalidStepResult $e) {
                self::assertSame($message, $e->getMessage());
            }
        }

        self::assertEquals($before, $campaign);
    }

    #[Test]
    public function aPickOfTooManyCandidatesIsRefusedWhole(): void
    {
        $campaign = $this->inScene('twice');
        $campaign->addNpc(NpcId::fromString('cid'), 'Cid', null, [], [], 1, $this->release);

        $this->expectException(InvalidStepResult::class);
        $this->expectExceptionMessageIsOrContains('Pick step "npc" takes 1 item, got 2.');

        $campaign->completeFlowStep('npc', StepResult::pick(ItemKind::Npc, [new PickedItem('ana', 'Ana'), new PickedItem('cid', 'Cid')]), $this->release, self::at());
    }

    #[Test]
    public function aChosenPickWithTooFewCandidatesRefusesAnyId(): void
    {
        $campaign = $this->inScene('duo');

        $this->expectException(InvalidStepResult::class);
        $this->expectExceptionMessageIsOrContains('Pick step "pair" needs 2 candidates, has 1: nothing can be picked.');

        $campaign->completeFlowStep('pair', StepResult::pick(ItemKind::Npc, [new PickedItem('ana', 'Ana')]), $this->release, self::at());
    }

    #[Test]
    public function aSkippedPickChangesNothing(): void
    {
        $campaign = $this->inScene('optional');

        $campaign->skipFlowStep('maybe', $this->release, self::at());

        self::assertSame([['setup', 'rest'], [], 'Rest []'], [self::position($campaign), self::flowRunOf($campaign)->answers(), $this->view($campaign)->step?->title]);
    }

    #[Test]
    public function theMostRecentPickReplacesThePreviousOneAndNamesAreReadWhenRendered(): void
    {
        $campaign = $this->inScene('twice');
        $campaign->completeFlowStep('npc', StepResult::pick(ItemKind::Npc, [new PickedItem('ana', 'Ana')]), $this->release, self::at());
        $campaign->editNpc(NpcId::fromString('ana'), 'Ana Vega', null, ['rival'], [], 1, $this->release);

        self::assertSame('Picked: Ana Vega', $this->view($campaign)->step?->title);
        $campaign->completeFlowStep('show', self::prompt('ok'), $this->release, self::at());

        $campaign->completeFlowStep('thread', StepResult::pick(ItemKind::Thread, [new PickedItem('t1', 'Rob the vault')]), $this->release, self::at());

        self::assertSame('Picked: Rob the vault', $this->view($campaign)->step?->title);
        self::assertSame([], $campaign->itemNames(ItemKind::Npc, ['ghost']), 'An id no longer present renders nothing.');
    }

    #[Test]
    public function nothingIsPickedWhenANewSceneStarts(): void
    {
        $campaign = $this->inScene('meet');
        $campaign->completeFlowStep('who', StepResult::pick(ItemKind::Npc, [new PickedItem('ana', 'Ana')]), $this->release, self::at());
        $campaign->completeFlowStep('after', self::prompt('ok'), $this->release, self::at());
        $campaign->endFlowScene(1, $this->release, self::at());

        $campaign->pickSceneType('quiet', $this->release, self::at());

        self::assertSame('Picked: []', $this->view($campaign)->step?->title);
    }

    private function campaign(): Campaign
    {
        $campaign = self::guided($this->release, 'play');
        $campaign->startSession(self::at(), $this->release);
        foreach ([['ana', 'Ana', 1], ['bea', 'Bea', 3]] as [$id, $name, $weight]) {
            $campaign->addNpc(NpcId::fromString($id), $name, null, ['rival'], [], $weight, $this->release);
        }

        $campaign->addThread(ThreadId::fromString('t1'), 'Rob the vault', [], [], 1, $this->release);
        $campaign->addThread(ThreadId::fromString('t2'), 'Find the ledger', [], [], 1, $this->release);

        return $campaign;
    }

    /**
     * The campaign of campaign() with the Scene Type picked; "duo" keeps one rival only.
     */
    private function inScene(string $sceneType): Campaign
    {
        $campaign = $this->campaign();
        if ('duo' === $sceneType) {
            $campaign->closeNpc(NpcId::fromString('bea'));
        }

        $campaign->pickSceneType($sceneType, $this->release, self::at());

        return $campaign;
    }

    private function view(Campaign $campaign): \App\Play\Domain\Campaign\FlowRun\FlowRunView
    {
        return $campaign->flowRunView($this->release) ?? self::fail('The campaign has no FlowRun.');
    }

    /**
     * @return array<string, mixed>
     */
    private function content(): array
    {
        $prompt = static fn (string $key, string $title, array $fields = []): array => ['key' => $key, 'kind' => 'prompt', 'title' => $title] + $fields;
        $sceneType = static fn (string $key, array $setup): array => ['key' => $key, 'name' => ucfirst($key), 'purpose' => 'A '.$key.' scene.', 'oracles' => [], 'setup' => $setup, 'play' => [], 'closing' => []];
        $sceneTypes = ['meet', 'lead', 'duo', 'twice', 'optional', 'quiet'];

        return [
            'schemaVersion' => 2,
            'gameSystem' => ['key' => 'pick-test', 'name' => 'Pick test'],
            'oracles' => ['tables' => [], 'likelihood' => []],
            'trackers' => [],
            'factSlots' => [],
            'tags' => [['key' => 'rival', 'label' => 'Rival'], ['key' => 'ally', 'label' => 'Ally']],
            'sceneTypes' => [
                $sceneType('meet', [
                    ['key' => 'who', 'kind' => 'pick', 'title' => 'Who?', 'of' => 'npc', 'tags' => ['rival'], 'mode' => 'random', 'count' => ['min' => 1, 'max' => 2], 'branches' => ['found' => ['effects' => [['kind' => 'sceneTitle', 'title' => 'Met {answer}']]], 'none' => ['next' => 'nobody']]],
                    $prompt('after', 'Then {picked}', ['next' => 'end']),
                    $prompt('nobody', 'Nobody: [{picked}]'),
                ]),
                $sceneType('lead', [
                    ['key' => 'lead', 'kind' => 'pick', 'title' => 'Which lead?', 'of' => 'thread', 'mode' => 'choose', 'count' => ['min' => 1, 'max' => 2]],
                    $prompt('then', 'Leads: {picked}'),
                ]),
                $sceneType('duo', [
                    ['key' => 'pair', 'kind' => 'pick', 'title' => 'Which pair?', 'of' => 'npc', 'tags' => ['rival'], 'mode' => 'choose', 'count' => ['min' => 2, 'max' => 2], 'branches' => ['none' => ['next' => 'alone']]],
                    $prompt('together', 'Together', ['next' => 'end']),
                    $prompt('alone', 'Alone'),
                ]),
                $sceneType('twice', [
                    ['key' => 'npc', 'kind' => 'pick', 'title' => 'Which NPC?', 'of' => 'npc', 'mode' => 'choose'],
                    $prompt('show', 'Picked: {picked}'),
                    ['key' => 'thread', 'kind' => 'pick', 'title' => 'Which Thread?', 'of' => 'thread', 'mode' => 'choose'],
                    $prompt('shown', 'Picked: {picked}'),
                ]),
                $sceneType('optional', [
                    ['key' => 'maybe', 'kind' => 'pick', 'title' => 'Maybe?', 'of' => 'npc', 'mode' => 'random', 'mandatory' => false],
                    $prompt('rest', 'Rest [{picked}]'),
                ]),
                $sceneType('quiet', [$prompt('quiet', 'Picked: [{picked}]')]),
            ],
            'flows' => [['key' => 'play', 'name' => 'Play', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => [], 'phases' => [
                ['key' => 'roam', 'name' => 'Roaming', 'mode' => 'loop', 'selection' => ['rule' => 'player', 'sceneTypes' => $sceneTypes]],
            ]]],
            'checks' => [],
        ];
    }
}
