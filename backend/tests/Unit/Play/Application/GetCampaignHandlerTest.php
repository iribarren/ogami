<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignNotFound;
use App\Play\Application\CampaignView;
use App\Play\Application\FlowSummaryView;
use App\Play\Application\GetCampaign;
use App\Play\Application\GetCampaignHandler;
use App\Play\Application\LikelihoodChaosView;
use App\Play\Application\LikelihoodLevelView;
use App\Play\Application\LikelihoodOracleView;
use App\Play\Application\OracleTableView;
use App\Play\Application\OwnedCampaigns;
use App\Play\Application\PinnedReleaseView;
use App\Play\Application\PublishedGameSystemReleases;
use App\Play\Application\SceneTypeSummaryView;
use App\Play\Application\SceneView;
use App\Play\Application\SessionView;
use App\Play\Application\TrackerLevelView;
use App\Play\Application\TrackerView;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\FlowRun\FlowRunStatus;
use App\Play\Domain\Campaign\Hook;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
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
#[CoversClass(OracleTableView::class)]
#[CoversClass(LikelihoodOracleView::class)]
#[CoversClass(LikelihoodLevelView::class)]
#[CoversClass(LikelihoodChaosView::class)]
#[CoversClass(TrackerView::class)]
#[CoversClass(TrackerLevelView::class)]
#[CoversClass(FlowSummaryView::class)]
#[CoversClass(SceneTypeSummaryView::class)]
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
            new PinnedReleaseView('free-journal', 'Free journal', 1),
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
        ), $view);
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
            new FlowSummaryView('the-heist', 'The heist', 'Plan it, pull it off, get away.', 'Every crew needs a score.', true, 'focus'),
            new FlowSummaryView('one-shot', 'One shot', null, null, false, 'journal'),
        ], $view->flows);
        self::assertEquals([
            new SceneTypeSummaryView('legwork', 'Legwork', 'Play out a legwork scene.'),
            new SceneTypeSummaryView('firefight', 'Firefight', 'Play out a firefight scene.'),
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
}
