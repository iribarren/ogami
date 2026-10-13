<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignNotFound;
use App\Play\Application\CampaignView;
use App\Play\Application\CastMemberView;
use App\Play\Application\CheckDifficultyView;
use App\Play\Application\CheckView;
use App\Play\Application\FactLinkView;
use App\Play\Application\FactSlotView;
use App\Play\Application\FactView;
use App\Play\Application\FlowSummaryView;
use App\Play\Application\GetCampaign;
use App\Play\Application\GetCampaignHandler;
use App\Play\Application\LikelihoodChaosView;
use App\Play\Application\LikelihoodLevelView;
use App\Play\Application\LikelihoodOracleView;
use App\Play\Application\NpcView;
use App\Play\Application\OracleTableView;
use App\Play\Application\OwnedCampaigns;
use App\Play\Application\PinnedReleaseView;
use App\Play\Application\PublishedGameSystemReleases;
use App\Play\Application\SceneTypeSummaryView;
use App\Play\Application\SceneView;
use App\Play\Application\SessionView;
use App\Play\Application\TagView;
use App\Play\Application\ThreadView;
use App\Play\Application\TrackerLevelView;
use App\Play\Application\TrackerView;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\Facts\Fact;
use App\Play\Domain\Campaign\Facts\FactFill;
use App\Play\Domain\Campaign\Facts\FactId;
use App\Play\Domain\Campaign\Facts\FactLink;
use App\Play\Domain\Campaign\FlowRun\FlowRunStatus;
use App\Play\Domain\Campaign\Hook;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\ThreadId;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\Campaign\Scene;
use App\Play\Domain\Campaign\Session;
use App\Play\Domain\GameSystem\FactSlot;
use App\Play\Domain\GameSystem\FactSlotType;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\Tag;
use App\Tests\Support\Play\InMemoryCampaignRepository;
use App\Tests\Support\Play\InMemoryPublishedGameSystemReleases;
use App\Tests\Support\Play\Snapshots;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GetCampaign::class)]
#[CoversClass(GetCampaignHandler::class)]
#[CoversClass(CampaignView::class)]
#[CoversClass(PinnedReleaseView::class)]
#[CoversClass(SessionView::class)]
#[CoversClass(SceneView::class)]
#[CoversClass(CastMemberView::class)]
#[CoversClass(OracleTableView::class)]
#[CoversClass(LikelihoodOracleView::class)]
#[CoversClass(LikelihoodLevelView::class)]
#[CoversClass(LikelihoodChaosView::class)]
#[CoversClass(TrackerView::class)]
#[CoversClass(TrackerLevelView::class)]
#[CoversClass(FlowSummaryView::class)]
#[CoversClass(SceneTypeSummaryView::class)]
#[CoversClass(TagView::class)]
#[CoversClass(CheckView::class)]
#[CoversClass(CheckDifficultyView::class)]
#[CoversClass(NpcView::class)]
#[CoversClass(ThreadView::class)]
#[CoversClass(FactSlotView::class)]
#[CoversClass(FactView::class)]
#[CoversClass(FactLinkView::class)]
final class GetCampaignHandlerTest extends TestCase
{
    private InMemoryCampaignRepository $campaigns;
    private InMemoryPublishedGameSystemReleases $releases;
    private GetCampaignHandler $handler;

    protected function setUp(): void
    {
        $this->campaigns = new InMemoryCampaignRepository();
        $this->releases = new InMemoryPublishedGameSystemReleases();
        $this->releases->add(Snapshots::withOracles('free-journal', 'Free journal', 1));
        // A newer release with no oracles: the campaign must keep reading its pinned v1.
        $this->releases->add(Snapshots::bare('free-journal', 'Free journal, revised', 2));
        $this->campaigns->add(Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'The lost mine', PinnedRelease::of('free-journal', 1, 'Free journal'), new \DateTimeImmutable('2026-10-05T10:00:00+00:00')));
        $this->handler = new GetCampaignHandler(new OwnedCampaigns($this->campaigns), $this->releases);
    }

    #[Test]
    public function aNewCampaignHasNoSessionAndTheOraclesOfItsPinnedRelease(): void
    {
        $view = ($this->handler)(new GetCampaign('campaign-1', 'user-1'));

        self::assertEquals(new CampaignView(
            'campaign-1',
            'The lost mine',
            new \DateTimeImmutable('2026-10-05T10:00:00+00:00'),
            new PinnedReleaseView('free-journal', 'Free journal', 1, null),
            [],
            null,
            null,
            [new OracleTableView('weather', 'Weather'), new OracleTableView('storm-kind', 'Storm kind')],
            [
                new LikelihoodOracleView('fate', 'Fate question', [new LikelihoodLevelView('unlikely', 'Unlikely'), new LikelihoodLevelView('even', '50/50')], new LikelihoodChaosView(1, 9, 5)),
                new LikelihoodOracleView('plain', 'Plain question', [new LikelihoodLevelView('even', 'Even')], null),
            ],
            [],
            null,
            null,
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            [],
        ), $view);
    }

    #[Test]
    public function itShowsTheChecksOfThePinnedReleaseWithoutTheirBands(): void
    {
        $this->releases->add(Snapshots::withChecks('heist', 'Heist', 1));
        // A newer release without checks: the campaign keeps reading its pinned v1.
        $this->releases->add(Snapshots::bare('heist', 'Heist, revised', 2));
        $this->campaigns->add(Campaign::create(CampaignId::fromString('campaign-2'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-10T09:00:00+00:00')));

        $view = ($this->handler)(new GetCampaign('campaign-2', 'user-1'));

        self::assertEquals([
            new CheckView('skill', 'Skill check', 'A stat against a Difficulty Value.', '1d10', new CheckDifficultyView('Difficulty Value', 13, 9, 29)),
            new CheckView('luck', 'Luck', null, '1d100', null),
        ], $view->checks);
        self::assertSame([], ($this->handler)(new GetCampaign('campaign-1', 'user-1'))->checks);
    }

    #[Test]
    public function itShowsTheTagsOfThePinnedReleaseAndTheNpcsAndThreadsOpenFirst(): void
    {
        $release = Snapshots::withTags('heist', 'Heist', 1);
        $this->releases->add($release);
        // A newer release with other Tags: the campaign keeps reading its pinned v1.
        $this->releases->add(new GameSystemSnapshot('heist', 'Heist, revised', 2, null, [], [], tags: [new Tag('boss', 'Boss')]));
        $campaign = Campaign::create(CampaignId::fromString('campaign-2'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-10T09:00:00+00:00'));
        $campaign->addNpc(NpcId::fromString('npc-1'), 'Mira', 'Wants the ledger', ['rival'], ['Fence'], 3, $release);
        $campaign->addNpc(NpcId::fromString('npc-2'), 'Brann', null, [], [], 1, $release);
        $campaign->addThread(ThreadId::fromString('thread-1'), 'Rob the vault', ['ally'], ['Heist'], 2, $release);
        $campaign->closeNpc(NpcId::fromString('npc-1'));
        $this->campaigns->add($campaign);

        $view = ($this->handler)(new GetCampaign('campaign-2', 'user-1'));

        self::assertEquals([new TagView('ally', 'Ally', null), new TagView('rival', 'Rival', 'Someone in the way')], $view->tags);
        self::assertEquals([
            new NpcView('npc-2', 'Brann', null, [], [], 1, 'open', null),
            new NpcView('npc-1', 'Mira', 'Wants the ledger', ['rival'], ['Fence'], 3, 'closed', null),
        ], $view->npcs);
        self::assertEquals([new ThreadView('thread-1', 'Rob the vault', ['ally'], ['Heist'], 2, 'open')], $view->threads);
    }

    #[Test]
    public function itShowsTheFactSlotsOfThePinnedReleaseAndTheFactsWithTheirValuesAndLinkNames(): void
    {
        $release = new GameSystemSnapshot('heist', 'Heist', 1, null, [], [], factSlots: [
            new FactSlot('city', 'City', FactSlotType::Text, 'Where it begins.'),
            new FactSlot('prince', 'The Prince', FactSlotType::Npc),
            new FactSlot('goal', 'Goal', FactSlotType::Thread),
        ]);
        $this->releases->add($release);
        // A newer release with no slots: the campaign keeps reading its pinned v1.
        $this->releases->add(new GameSystemSnapshot('heist', 'Heist, revised', 2, null, [], []));
        $campaign = Campaign::create(CampaignId::fromString('campaign-2'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-10T09:00:00+00:00'));
        $campaign->addNpc(NpcId::fromString('npc-1'), 'Mira', null, [], [], 1, $release);
        $campaign->addFreeFact(FactId::fromString('free-1'), 'Guild', 'Owes a debt', [FactLink::npc(NpcId::fromString('npc-1'))], $release);
        $campaign->fillFactSlot(FactId::fromString('goal-1'), 'goal', FactFill::newThread(ThreadId::fromString('thread-1'), 'Find the heir'), $release);
        $campaign->fillFactSlot(FactId::fromString('city-1'), 'city', FactFill::text('Ashford'), $release);
        $campaign->fillFactSlot(FactId::fromString('prince-1'), 'prince', FactFill::existingNpc(NpcId::fromString('npc-1')), $release);
        $campaign->editFact(FactId::fromString('prince-1'), null, null, [FactLink::fact(FactId::fromString('free-1')), FactLink::thread(ThreadId::fromString('thread-1'))], $release);
        $campaign->editNpc(NpcId::fromString('npc-1'), 'Mira Vane', null, [], [], 1, $release);
        $this->campaigns->add($campaign);

        $view = ($this->handler)(new GetCampaign('campaign-2', 'user-1'));

        self::assertEquals([new FactSlotView('city', 'City', 'text', 'Where it begins.'), new FactSlotView('prince', 'The Prince', 'npc', null), new FactSlotView('goal', 'Goal', 'thread', null)], $view->factSlots);
        self::assertEquals([
            new FactView('city-1', 'city', 'City', 'Ashford', null, 'Ashford', []),
            new FactView('prince-1', 'prince', 'The Prince', null, new FactLinkView('npc', 'npc-1', 'Mira Vane'), 'Mira Vane', [new FactLinkView('fact', 'free-1', 'Guild'), new FactLinkView('thread', 'thread-1', 'Find the heir')]),
            new FactView('goal-1', 'goal', 'Goal', null, new FactLinkView('thread', 'thread-1', 'Find the heir'), 'Find the heir', []),
            new FactView('free-1', null, 'Guild', 'Owes a debt', null, 'Owes a debt', [new FactLinkView('npc', 'npc-1', 'Mira Vane')]),
        ], $view->facts);
    }

    #[Test]
    public function aLinkToASlotFactShowsTheSlotLabel(): void
    {
        $release = new GameSystemSnapshot('heist', 'Heist', 1, null, [], [], factSlots: [new FactSlot('city', 'City', FactSlotType::Text)]);
        $this->releases->add($release);
        $campaign = Campaign::create(CampaignId::fromString('campaign-2'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-10T09:00:00+00:00'));
        $campaign->fillFactSlot(FactId::fromString('city-1'), 'city', FactFill::text('Ashford'), $release);
        $campaign->addFreeFact(FactId::fromString('free-1'), 'Guild', 'Owes a debt', [FactLink::fact(FactId::fromString('city-1'))], $release);
        $this->campaigns->add($campaign);

        $view = ($this->handler)(new GetCampaign('campaign-2', 'user-1'));

        self::assertEquals([new FactLinkView('fact', 'city-1', 'City')], $view->facts[1]->links);
    }

    #[Test]
    public function itShowsTheFlowPlayedAndTheFlowsAndSceneTypesOfThePinnedRelease(): void
    {
        $snapshot = Snapshots::withFlows('heist', 'Heist', 1);
        $this->releases->add($snapshot);
        $this->campaigns->add(Campaign::create(CampaignId::fromString('campaign-2'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-10T09:00:00+00:00'), [], $snapshot->flow('one-shot')));

        $view = ($this->handler)(new GetCampaign('campaign-2', 'user-1'));

        self::assertSame('one-shot', $view->flowKey);
        self::assertSame([FlowRunStatus::Active, true, ['legwork', 'firefight']], [$view->flowRun?->status, $view->flowRun?->waitsForSession, array_map(static fn ($sceneType): string => $sceneType->key, $view->flowRun?->pick->cards ?? [])]);
        self::assertEquals([
            new FlowSummaryView('the-heist', 'The heist', 'Plan it, pull it off, get away.', 'Every crew needs a score.', true, 'focus', [], []),
            new FlowSummaryView('one-shot', 'One shot', null, null, false, 'journal', [], []),
        ], $view->flows);
        self::assertEquals([
            new SceneTypeSummaryView('legwork', 'Legwork', 'Play out a legwork scene.', []),
            new SceneTypeSummaryView('firefight', 'Firefight', 'Play out a firefight scene.', []),
        ], $view->sceneTypes);
    }

    #[Test]
    public function anEndedSessionShowsItsEndAndLeavesNoCurrentSession(): void
    {
        $campaign = $this->campaigns->ofId(CampaignId::fromString('campaign-1'));
        self::assertNotNull($campaign);
        $campaign->startSession(new \DateTimeImmutable('2026-10-06T09:00:00+00:00'));
        $campaign->startScene('At the gate', new \DateTimeImmutable('2026-10-06T09:10:00+00:00'));
        $campaign->endSession(new \DateTimeImmutable('2026-10-06T12:00:00+00:00'));
        $this->campaigns->save($campaign);

        $view = ($this->handler)(new GetCampaign('campaign-1', 'user-1'));

        self::assertEquals(new \DateTimeImmutable('2026-10-06T12:00:00+00:00'), $view->sessions[0]->endedAt);
        self::assertNull($view->currentSessionNumber);
        self::assertNull($view->currentSceneNumber);
    }

    #[Test]
    public function itShowsTheTrackersOfThePinnedReleaseWithTheirValuesAndLevels(): void
    {
        $this->releases->add(Snapshots::withTrackers('heist', 'Heist', 1));
        $campaign = Campaign::create(CampaignId::fromString('campaign-2'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-09T09:00:00+00:00'), Snapshots::withTrackers('heist', 'Heist', 1)->trackers());
        $heat = Snapshots::withTrackers('heist', 'Heist', 1)->tracker('heat');
        self::assertNotNull($heat);
        $campaign->setTrackerValue($heat, 0);
        $this->campaigns->add($campaign);

        $view = ($this->handler)(new GetCampaign('campaign-2', 'user-1'));

        $levels = [new TrackerLevelView(-1, 'Cold'), new TrackerLevelView(2, 'Warm'), new TrackerLevelView(null, 'Hot')];
        self::assertEquals([
            new TrackerView('alarm', 'Alarm', 'clock', 'At 6/6 security locks down', 0, 6, 6, [], 0, null),
            new TrackerView('heat', 'Heat', 'counter', null, -5, 5, null, $levels, 0, 'Warm'),
            new TrackerView('chaos', 'Chaos factor', 'counter', null, 1, 9, null, [], 5, null),
        ], $view->trackers);
        self::assertSame(['fate' => 'chaos', 'omen' => null], array_column(array_map(static fn (LikelihoodOracleView $oracle): array => [$oracle->key, $oracle->chaosTracker], $view->likelihoodOracles), 1, 0));
    }

    #[Test]
    public function itShowsSessionsWithTheirScenesAndTheCurrentOnes(): void
    {
        $campaign = $this->campaigns->ofId(CampaignId::fromString('campaign-1'));
        self::assertNotNull($campaign);
        $campaign->startSession(new \DateTimeImmutable('2026-10-06T09:00:00+00:00'));
        $campaign->startScene('At the gate', new \DateTimeImmutable('2026-10-06T09:10:00+00:00'));
        $campaign->startScene('In the mine', new \DateTimeImmutable('2026-10-06T09:20:00+00:00'));
        $campaign->startSession(new \DateTimeImmutable('2026-10-07T09:00:00+00:00'));
        $this->campaigns->save($campaign);

        $view = ($this->handler)(new GetCampaign('campaign-1', 'user-1'));

        self::assertEquals([
            new SessionView(1, new \DateTimeImmutable('2026-10-06T09:00:00+00:00'), [
                new SceneView(1, 'At the gate', new \DateTimeImmutable('2026-10-06T09:10:00+00:00')),
                new SceneView(2, 'In the mine', new \DateTimeImmutable('2026-10-06T09:20:00+00:00')),
            ]),
            new SessionView(2, new \DateTimeImmutable('2026-10-07T09:00:00+00:00'), []),
        ], $view->sessions);
        self::assertSame(2, $view->currentSessionNumber);
        self::assertNull($view->currentSceneNumber);
    }

    #[Test]
    public function aSceneShowsItsKindItsSceneTypeWithItsNameAndItsHook(): void
    {
        $snapshot = Snapshots::withSceneTypes('heist', 'Heist', 1);
        $this->releases->add($snapshot);
        $campaign = Campaign::create(CampaignId::fromString('campaign-2'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-10T09:00:00+00:00'));
        $campaign->startSession(new \DateTimeImmutable('2026-10-10T09:00:00+00:00'));
        $campaign->startHookScene(Hook::SessionOpening, 'Session 1 begins', new \DateTimeImmutable('2026-10-10T09:01:00+00:00'));
        $campaign->startScene(null, new \DateTimeImmutable('2026-10-10T09:02:00+00:00'), $snapshot->sceneType('legwork'));
        $campaign->startScene('A quiet drink', new \DateTimeImmutable('2026-10-10T09:03:00+00:00'));
        $this->campaigns->add($campaign);

        $scenes = ($this->handler)(new GetCampaign('campaign-2', 'user-1'))->sessions[0]->scenes;

        self::assertEquals([
            new SceneView(1, 'Session 1 begins', new \DateTimeImmutable('2026-10-10T09:01:00+00:00'), 'hook', null, null, 'sessionOpening'),
            new SceneView(2, 'Legwork 1', new \DateTimeImmutable('2026-10-10T09:02:00+00:00'), 'scene', 'legwork', 'Legwork'),
            new SceneView(3, 'A quiet drink', new \DateTimeImmutable('2026-10-10T09:03:00+00:00'), 'scene'),
        ], $scenes);
    }

    #[Test]
    public function aSceneShowsItsCastWithTheNamesTheCampaignHasNow(): void
    {
        $snapshot = Snapshots::withTags('people', 'People', 1);
        $this->releases->add($snapshot);
        $campaign = Campaign::create(CampaignId::fromString('campaign-3'), 'user-1', 'The crew', PinnedRelease::of('people', 1, 'People'), new \DateTimeImmutable('2026-10-10T09:00:00+00:00'));
        $campaign->startSession(new \DateTimeImmutable('2026-10-10T09:00:00+00:00'));
        $campaign->startScene('Old friends', new \DateTimeImmutable('2026-10-10T09:01:00+00:00'));
        $campaign->addNpc(NpcId::fromString('ana'), 'Ana', null, [], [], 1, $snapshot);
        $campaign->addNpc(NpcId::fromString('bea'), 'Bea', null, [], [], 1, $snapshot);
        $campaign->editCurrentSceneCast([NpcId::fromString('bea'), NpcId::fromString('ana')]);
        $campaign->editNpc(NpcId::fromString('ana'), 'Ana Vega', null, [], [], 1, $snapshot);
        $campaign->closeNpc(NpcId::fromString('bea'));
        $campaign->startScene('Nobody', new \DateTimeImmutable('2026-10-10T09:02:00+00:00'));
        $this->campaigns->add($campaign);

        $scenes = ($this->handler)(new GetCampaign('campaign-3', 'user-1'))->sessions[0]->scenes;

        self::assertEquals([new CastMemberView('bea', 'Bea'), new CastMemberView('ana', 'Ana Vega')], $scenes[0]->cast);
        self::assertSame([], $scenes[1]->cast);
    }

    #[Test]
    public function aCastNamingAnNpcTheCampaignDoesNotHoldFailsClosed(): void
    {
        $snapshot = Snapshots::withTags('people', 'People', 1);
        $this->releases->add($snapshot);
        $at = new \DateTimeImmutable('2026-10-10T09:00:00+00:00');
        $this->campaigns->add(Campaign::reconstitute(
            CampaignId::fromString('campaign-4'),
            'user-1',
            'The crew',
            PinnedRelease::of('people', 1, 'People'),
            $at,
            [Session::reconstitute(1, $at, [Scene::reconstitute(1, 'Ghosts', $at, cast: [NpcId::fromString('ghost')])])],
        ));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('Scene 1 casts NPC "ghost", which the campaign does not hold.');

        ($this->handler)(new GetCampaign('campaign-4', 'user-1'));
    }

    #[Test]
    public function aSlotFactWhoseSlotTheReleaseDoesNotDeclareFailsClosed(): void
    {
        $this->releases->add(new GameSystemSnapshot('lore', 'Lore', 1, null, [], [], factSlots: [new FactSlot('city', 'City', FactSlotType::Text)]));
        $this->campaigns->add($this->campaignWithFacts('campaign-5', [
            Fact::of(FactId::fromString('fact-1'), 'ghost', null, 'Boo', null, []),
        ]));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('Fact "fact-1" fills slot "ghost", which the pinned release does not declare.');

        ($this->handler)(new GetCampaign('campaign-5', 'user-1'));
    }

    #[Test]
    public function aLinkToASlotFactWhoseSlotTheReleaseDoesNotDeclareFailsClosed(): void
    {
        $this->releases->add(new GameSystemSnapshot('lore', 'Lore', 1, null, [], [], factSlots: [new FactSlot('city', 'City', FactSlotType::Text)]));
        $this->campaigns->add($this->campaignWithFacts('campaign-5', [
            Fact::of(FactId::fromString('fact-1'), 'city', null, 'Ashford', null, [FactLink::fact(FactId::fromString('fact-2'))]),
            Fact::of(FactId::fromString('fact-2'), 'ghost', null, 'Boo', null, []),
        ]));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('Fact "fact-2" fills slot "ghost", which the pinned release does not declare.');

        ($this->handler)(new GetCampaign('campaign-5', 'user-1'));
    }

    #[Test]
    public function theCurrentSceneIsTheLatestSceneOfTheCurrentSession(): void
    {
        $campaign = $this->campaigns->ofId(CampaignId::fromString('campaign-1'));
        self::assertNotNull($campaign);
        $campaign->startSession(new \DateTimeImmutable('2026-10-06T09:00:00+00:00'));
        $campaign->startScene('At the gate', new \DateTimeImmutable('2026-10-06T09:10:00+00:00'));
        $this->campaigns->save($campaign);

        $view = ($this->handler)(new GetCampaign('campaign-1', 'user-1'));

        self::assertSame(1, $view->currentSessionNumber);
        self::assertSame(1, $view->currentSceneNumber);
    }

    #[Test]
    public function anotherPlayersCampaignIsNotFound(): void
    {
        $this->expectException(CampaignNotFound::class);
        $this->expectExceptionMessageIsOrContains('Campaign "campaign-1" not found.');

        ($this->handler)(new GetCampaign('campaign-1', 'user-2'));
    }

    #[Test]
    public function anUnknownCampaignIsNotFound(): void
    {
        $this->expectException(CampaignNotFound::class);

        ($this->handler)(new GetCampaign('campaign-9', 'user-1'));
    }

    #[Test]
    public function aPinnedReleaseThatIsNoLongerPublishedIsNotFound(): void
    {
        $this->campaigns->add(Campaign::create(CampaignId::fromString('campaign-2'), 'user-1', 'The lost city', PinnedRelease::of('free-journal', 7, 'Free journal'), new \DateTimeImmutable('2026-10-05T10:00:00+00:00')));

        $this->expectException(GameSystemReleaseNotFound::class);
        $this->expectExceptionMessageIsOrContains('No published release v7 of GameSystem "free-journal" is available to Play.');

        ($this->handler)(new GetCampaign('campaign-2', 'user-1'));
    }

    #[Test]
    public function aPinnedReleaseThatCannotBeReadSurfacesThePlayError(): void
    {
        $unreadable = InvalidGameSystemRelease::of('free-journal', 1, 'oracles', 'broken.');
        $releases = new readonly class($unreadable) implements PublishedGameSystemReleases {
            public function __construct(private InvalidGameSystemRelease $error)
            {
            }

            public function get(string $gameSystemKey, ?int $version = null): GameSystemSnapshot
            {
                throw $this->error;
            }

            public function latest(): array
            {
                return [];
            }
        };
        $handler = new GetCampaignHandler(new OwnedCampaigns($this->campaigns), $releases);

        try {
            $handler(new GetCampaign('campaign-1', 'user-1'));
            self::fail('An unreadable pinned release was accepted.');
        } catch (InvalidGameSystemRelease $exception) {
            self::assertSame($unreadable, $exception);
        }
    }

    /**
     * A campaign pinned to "lore" v1 that holds these facts, as a stored one does.
     *
     * @param list<Fact> $facts
     */
    private function campaignWithFacts(string $id, array $facts): Campaign
    {
        return Campaign::reconstitute(CampaignId::fromString($id), 'user-1', 'The lore', PinnedRelease::of('lore', 1, 'Lore'), new \DateTimeImmutable('2026-10-10T09:00:00+00:00'), [], facts: $facts);
    }
}
