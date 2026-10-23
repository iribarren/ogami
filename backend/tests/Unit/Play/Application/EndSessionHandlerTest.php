<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\EndSession;
use App\Play\Application\EndSessionHandler;
use App\Play\Application\OwnedCampaigns;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\FlowRun\FlowRunStatus;
use App\Play\Domain\Campaign\FlowRun\SessionCannotEndWhileGuided;
use App\Play\Domain\Campaign\Hook;
use App\Play\Domain\Campaign\NoCurrentSession;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Tests\Support\Play\FixedClock;
use App\Tests\Support\Play\FlowOutcomesFor;
use App\Tests\Support\Play\GuidedReleases;
use App\Tests\Support\Play\InMemoryCampaignRepository;
use App\Tests\Support\Play\InMemoryJournalEntryRepository;
use App\Tests\Support\Play\InMemoryPublishedGameSystemReleases;
use App\Tests\Support\Play\UnreadablePublishedGameSystemReleases;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EndSession::class)]
#[CoversClass(EndSessionHandler::class)]
final class EndSessionHandlerTest extends TestCase
{
    private InMemoryCampaignRepository $campaigns;
    private EndSessionHandler $handler;
    private GameSystemSnapshot $release;

    protected function setUp(): void
    {
        $this->campaigns = new InMemoryCampaignRepository();
        $campaign = Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'The lost mine', PinnedRelease::of('free-journal', 1, 'Free journal'), new \DateTimeImmutable('2026-10-05T10:00:00+00:00'));
        $campaign->startSession(new \DateTimeImmutable('2026-10-06T09:00:00+00:00'));
        $this->campaigns->add($campaign);
        // "campaign-2" plays Flow "tour" of a release whose phases close a session with "notes".
        $this->release = GuidedReleases::tour(sessionClosing: true);
        $releases = new InMemoryPublishedGameSystemReleases();
        $releases->add($this->release);
        $guided = Campaign::create(CampaignId::fromString('campaign-2'), 'user-1', 'The tour', PinnedRelease::of('guided', 1, 'Guided'), new \DateTimeImmutable('2026-10-05T10:00:00+00:00'), [], $this->release->flow('tour'));
        $guided->startSession(new \DateTimeImmutable('2026-10-06T09:00:00+00:00'), $this->release);
        $this->campaigns->add($guided);
        $this->handler = new EndSessionHandler(new OwnedCampaigns($this->campaigns), $this->campaigns, $releases, $clock = new FixedClock('2026-10-06T12:00:00+00:00'), FlowOutcomesFor::of($this->campaigns, new InMemoryJournalEntryRepository(), $releases, $clock));
    }

    #[Test]
    public function itEndsTheCurrentSessionAtTheClocksTime(): void
    {
        ($this->handler)(new EndSession('campaign-1', 'user-1'));

        $campaign = $this->campaigns->ofId(CampaignId::fromString('campaign-1'));
        self::assertNotNull($campaign);
        self::assertEquals(new \DateTimeImmutable('2026-10-06T12:00:00+00:00'), $campaign->sessions()[0]->endedAt());
        self::assertNull($campaign->currentSession());
    }

    #[Test]
    public function anEndedSessionCannotEndAgain(): void
    {
        ($this->handler)(new EndSession('campaign-1', 'user-1'));

        $this->expectException(NoCurrentSession::class);

        ($this->handler)(new EndSession('campaign-1', 'user-1'));
    }

    #[Test]
    public function aGuidedSessionRunsItsSessionClosingAtTheScenePickAndEndsAfterIt(): void
    {
        ($this->handler)(new EndSession('campaign-2', 'user-1', 1));

        $campaign = $this->stored('campaign-2');
        self::assertSame([1, Hook::SessionClosing, 'Session 1 ends', 'notes'], [$campaign->currentSession()?->number(), $campaign->currentScene()?->hook(), $campaign->currentScene()?->title(), $campaign->flowRun()?->stepKey()]);
        $campaign->skipFlowStep('notes', $this->release, new \DateTimeImmutable('2026-10-06T12:05:00+00:00'));
        self::assertEquals(new \DateTimeImmutable('2026-10-06T12:05:00+00:00'), $campaign->sessions()[0]->endedAt());
    }

    #[Test]
    public function aGuidedSessionInASceneDoesNotEnd(): void
    {
        $campaign = $this->stored('campaign-2');
        $campaign->pickSceneType('tour', $this->release, new \DateTimeImmutable('2026-10-06T10:00:00+00:00'));
        $this->campaigns->save($campaign);
        $before = $this->stored('campaign-2');

        try {
            ($this->handler)(new EndSession('campaign-2', 'user-1', 1));
            self::fail('A guided session ended in a scene.');
        } catch (SessionCannotEndWhileGuided) {
            self::assertEquals($before, $this->stored('campaign-2'));
        }
    }

    #[Test]
    public function aPausedOrCompletedFlowRunEndsTheSessionWithoutReadingTheUnreadableRelease(): void
    {
        $paused = $this->stored('campaign-2');
        $paused->pauseGuidance(new \DateTimeImmutable('2026-10-06T09:30:00+00:00'));
        $completed = Campaign::create(CampaignId::fromString('campaign-3'), 'user-1', 'The end', PinnedRelease::of('guided', 1, 'Guided'), new \DateTimeImmutable('2026-10-05T10:00:00+00:00'), [], $this->release->flow('tour'));
        $completed->startSession(new \DateTimeImmutable('2026-10-06T09:00:00+00:00'), $this->release);
        $completed->moveOn('tour', $this->release, new \DateTimeImmutable('2026-10-06T09:30:00+00:00'));
        $this->campaigns->save($paused);
        $this->campaigns->add($completed);
        $handler = new EndSessionHandler(new OwnedCampaigns($this->campaigns), $this->campaigns, $unreadable = new UnreadablePublishedGameSystemReleases(GameSystemReleaseNotFound::for('guided', 1)), $clock = new FixedClock('2026-10-06T12:00:00+00:00'), FlowOutcomesFor::of($this->campaigns, new InMemoryJournalEntryRepository(), $unreadable, $clock));

        foreach (['campaign-2' => FlowRunStatus::Paused, 'campaign-3' => FlowRunStatus::Completed] as $id => $status) {
            $handler(new EndSession($id, 'user-1', 1));

            self::assertSame([$status, null], [$this->stored($id)->flowRun()?->status(), $this->stored($id)->currentSession()]);
            self::assertEquals(new \DateTimeImmutable('2026-10-06T12:00:00+00:00'), $this->stored($id)->sessions()[0]->endedAt());
        }
    }

    #[Test]
    public function anActiveFlowRunWithAnUnreadableReleaseStillFailsToEndTheSession(): void
    {
        $handler = new EndSessionHandler(new OwnedCampaigns($this->campaigns), $this->campaigns, $unreadable = new UnreadablePublishedGameSystemReleases(GameSystemReleaseNotFound::for('guided', 1)), $clock = new FixedClock('2026-10-06T12:00:00+00:00'), FlowOutcomesFor::of($this->campaigns, new InMemoryJournalEntryRepository(), $unreadable, $clock));

        $this->expectException(GameSystemReleaseNotFound::class);

        $handler(new EndSession('campaign-2', 'user-1', 1));
    }

    private function stored(string $id): Campaign
    {
        return $this->campaigns->ofId(CampaignId::fromString($id)) ?? throw new \LogicException('No campaign '.$id);
    }
}
