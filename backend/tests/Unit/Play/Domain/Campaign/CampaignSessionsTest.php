<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\NoCurrentScene;
use App\Play\Domain\Campaign\NoCurrentSession;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\Campaign\Session;
use App\Play\Domain\GameSystem\Flow\Flow;
use App\Play\Domain\GameSystem\Flow\FlowView;
use App\Play\Domain\GameSystem\Flow\Phase;
use App\Play\Domain\GameSystem\Flow\PhaseMode;
use App\Play\Domain\GameSystem\Flow\SceneSelection;
use App\Play\Domain\GameSystem\Flow\StepList;
use App\Play\Domain\GameSystem\SceneType;
use App\Tests\Support\Play\CampaignCopies;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Sessions as sittings: ending the current one, starting the next, and the Flow a campaign plays.
 */
#[CoversClass(Campaign::class)]
#[CoversClass(Session::class)]
#[CoversClass(NoCurrentSession::class)]
final class CampaignSessionsTest extends TestCase
{
    #[Test]
    public function endingTheCurrentSessionKeepsItsEndAndLeavesNoSessionUnderWay(): void
    {
        $campaign = $this->campaignInASession();
        $campaign->startScene('At the gate', new \DateTimeImmutable());

        $ended = $campaign->endSession(new \DateTimeImmutable('2026-10-10 12:00:00'));

        self::assertSame(1, $ended->number());
        self::assertEquals(new \DateTimeImmutable('2026-10-10 12:00:00'), $ended->endedAt());
        self::assertTrue($ended->hasEnded());
        self::assertEquals([$ended], $campaign->sessions());
        self::assertSame(['At the gate'], array_map(static fn (\App\Play\Domain\Campaign\Scene $scene): string => $scene->title(), $ended->scenes()));
        self::assertNull($campaign->currentSession());
        self::assertNull($campaign->currentScene());
    }

    #[Test]
    public function aSessionUnderWayHasNotEnded(): void
    {
        $session = $this->campaignInASession()->currentSession();

        self::assertNotNull($session);
        self::assertNull($session->endedAt());
        self::assertFalse($session->hasEnded());
    }

    #[Test]
    public function noSceneStartsOnceTheSessionHasEnded(): void
    {
        $campaign = $this->campaignInASession();
        $campaign->endSession(new \DateTimeImmutable());

        $this->expectException(NoCurrentSession::class);
        $this->expectExceptionMessageIsOrContains('Start a session before starting a scene.');

        $campaign->startScene('Too late', new \DateTimeImmutable(), $this->sceneType());
    }

    #[Test]
    public function noSceneTypeSwitchesOnceTheSessionHasEnded(): void
    {
        $campaign = $this->campaignInASession();
        $campaign->startScene('At the gate', new \DateTimeImmutable());
        $campaign->endSession(new \DateTimeImmutable());

        $this->expectException(NoCurrentScene::class);

        $campaign->switchSceneType($this->sceneType());
    }

    #[Test]
    public function theNextSessionStartsAfterTheEndedOne(): void
    {
        $campaign = $this->campaignInASession();
        $campaign->endSession(new \DateTimeImmutable());

        $next = $campaign->startSession(new \DateTimeImmutable('2026-10-11 09:00:00'));
        $scene = $campaign->startScene('Back at it', new \DateTimeImmutable());

        self::assertSame([2, null], [$next->number(), $next->endedAt()]);
        self::assertSame(2, $campaign->currentSession()?->number());
        self::assertSame([1, 'Back at it'], [$scene->number(), $campaign->currentScene()?->title()]);
        self::assertTrue($campaign->sessions()[0]->hasEnded());
    }

    #[Test]
    public function anEndedSessionCannotEndAgain(): void
    {
        $campaign = $this->campaignInASession();
        $campaign->endSession(new \DateTimeImmutable('2026-10-10 12:00:00'));

        try {
            $campaign->endSession(new \DateTimeImmutable('2026-10-10 13:00:00'));
            self::fail('An ended session ended again.');
        } catch (NoCurrentSession $exception) {
            self::assertSame('No session is under way: start a session before ending one.', $exception->getMessage());
        }

        self::assertEquals(new \DateTimeImmutable('2026-10-10 12:00:00'), $campaign->sessions()[0]->endedAt());
    }

    #[Test]
    public function onlyTheSessionUnderWayEndsWhenTheCallerNamesIt(): void
    {
        $campaign = $this->campaignInASession();

        try {
            $campaign->endSession(new \DateTimeImmutable('2026-10-10 12:00:00'), 2);
            self::fail('A session ended that is not the one under way.');
        } catch (NoCurrentSession $exception) {
            self::assertSame('Session 2 is not the session under way.', $exception->getMessage());
        }

        self::assertFalse($campaign->sessions()[0]->hasEnded());
        self::assertSame(1, $campaign->endSession(new \DateTimeImmutable('2026-10-10 12:00:00'), 1)->number());
    }

    #[Test]
    public function noSessionEndsBeforeTheFirstOne(): void
    {
        $this->expectException(NoCurrentSession::class);

        $this->campaign()->endSession(new \DateTimeImmutable());
    }

    #[Test]
    public function aStoredSessionKeepsItsEndAndOneStoredWithoutIsUnderWay(): void
    {
        $endedAt = new \DateTimeImmutable('2026-10-10 12:00:00');

        self::assertEquals($endedAt, Session::reconstitute(1, new \DateTimeImmutable(), [], $endedAt)->endedAt());
        self::assertNull(Session::reconstitute(1, new \DateTimeImmutable(), [])->endedAt());
    }

    #[Test]
    public function aCampaignKeepsTheFlowItWasCreatedWithOrNone(): void
    {
        $guided = Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable(), [], $this->flow('heist'));

        self::assertSame('heist', $guided->flowKey());
        self::assertNull($this->campaign()->flowKey());
    }

    #[Test]
    public function aStoredCampaignKeepsItsFlowKey(): void
    {
        $original = Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable(), [], $this->flow('heist'));

        $campaign = Campaign::reconstitute($original->id(), $original->ownerId(), $original->name(), $original->pinnedRelease(), $original->createdAt(), [], [], 'heist');

        // The FlowRun is stored from play-flow-run slice 15 on.
        self::assertNotNull($original->flowRun());
        self::assertEquals(CampaignCopies::withoutFlowRun($original), $campaign);
    }

    private function campaign(): Campaign
    {
        return Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-10 09:00:00'));
    }

    private function campaignInASession(): Campaign
    {
        $campaign = $this->campaign();
        $campaign->startSession(new \DateTimeImmutable('2026-10-10 09:05:00'));

        return $campaign;
    }

    private function sceneType(): SceneType
    {
        return new SceneType('legwork', 'Legwork', 'A purpose.', null, [], new StepList(), new StepList(), new StepList());
    }

    private function flow(string $key): Flow
    {
        return new Flow($key, 'The heist', null, null, true, FlowView::Journal, [], [], [new Phase('plan', 'Plan', null, PhaseMode::Once, SceneSelection::player(['legwork']))]);
    }
}
