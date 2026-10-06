<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\CampaignLimitReached;
use App\Play\Domain\Campaign\InvalidCampaignName;
use App\Play\Domain\Campaign\InvalidCampaignOwner;
use App\Play\Domain\Campaign\InvalidSceneTitle;
use App\Play\Domain\Campaign\NoCurrentSession;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\Campaign\Scene;
use App\Play\Domain\Campaign\Session;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Campaign::class)]
#[CoversClass(Session::class)]
#[CoversClass(Scene::class)]
#[CoversClass(InvalidCampaignName::class)]
#[CoversClass(InvalidCampaignOwner::class)]
#[CoversClass(InvalidSceneTitle::class)]
#[CoversClass(NoCurrentSession::class)]
#[CoversClass(CampaignLimitReached::class)]
final class CampaignTest extends TestCase
{
    private const string ID = '01890a5d-ac96-774b-bcce-b302099a8057';

    #[Test]
    public function aNewCampaignKeepsItsOwnerNamePinnedReleaseAndCreationTime(): void
    {
        $pinned = PinnedRelease::of('free-journal', 2, 'Free journal');
        $createdAt = new \DateTimeImmutable('2026-10-06 10:00:00');

        $campaign = Campaign::create(CampaignId::fromString(self::ID), 'user-1', 'The Long Road', $pinned, $createdAt);

        self::assertTrue(CampaignId::fromString(self::ID)->equals($campaign->id()));
        self::assertSame('user-1', $campaign->ownerId());
        self::assertSame('The Long Road', $campaign->name());
        self::assertTrue($pinned->equals($campaign->pinnedRelease()));
        self::assertEquals($createdAt, $campaign->createdAt());
        self::assertSame([], $campaign->sessions());
        self::assertNull($campaign->currentSession());
        self::assertNull($campaign->currentScene());
    }

    #[Test]
    public function theNameIsTrimmed(): void
    {
        self::assertSame('The Long Road', $this->campaign("  The Long Road \n")->name());
    }

    #[Test]
    public function aNameOfOneHundredCharactersIsAccepted(): void
    {
        $name = str_repeat('é', 100);

        self::assertSame($name, $this->campaign($name)->name());
    }

    #[Test]
    public function aNameAboveOneHundredCharactersIsRejected(): void
    {
        $this->expectException(InvalidCampaignName::class);
        $this->expectExceptionMessageIsOrContains('A campaign name must be at most 100 characters, got 101.');

        $this->campaign(str_repeat('a', 101));
    }

    #[Test]
    #[DataProvider('blankNames')]
    public function aBlankNameIsRejected(string $name): void
    {
        $this->expectException(InvalidCampaignName::class);
        $this->expectExceptionMessageIsOrContains('A campaign name must not be blank.');

        $this->campaign($name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankNames(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'whitespace' => [" \t\n "];
    }

    #[Test]
    #[DataProvider('blankNames')]
    public function aBlankOwnerIdIsRejected(string $ownerId): void
    {
        $this->expectException(InvalidCampaignOwner::class);
        $this->expectExceptionMessageIsOrContains('A campaign owner id must not be blank.');

        Campaign::create(
            CampaignId::fromString(self::ID),
            $ownerId,
            'The Long Road',
            PinnedRelease::of('free-journal', 1, 'Free journal'),
            new \DateTimeImmutable('2026-10-06 10:00:00'),
        );
    }

    #[Test]
    public function onlyTheOwnerOwnsTheCampaign(): void
    {
        $campaign = $this->campaign();

        self::assertTrue($campaign->isOwnedBy('user-1'));
        self::assertFalse($campaign->isOwnedBy('user-2'));
        self::assertFalse($campaign->isOwnedBy(''));
    }

    #[Test]
    public function sessionsAreNumberedFromOneAndTheLatestIsCurrent(): void
    {
        $campaign = $this->campaign();
        $first = new \DateTimeImmutable('2026-10-06 10:00:00');
        $second = new \DateTimeImmutable('2026-10-07 10:00:00');

        $campaign->startSession($first);
        $campaign->startSession($second);

        $sessions = $campaign->sessions();
        self::assertCount(2, $sessions);
        self::assertSame(1, $sessions[0]->number());
        self::assertEquals($first, $sessions[0]->startedAt());
        self::assertSame(2, $sessions[1]->number());
        self::assertEquals($second, $sessions[1]->startedAt());
        self::assertSame(2, $campaign->currentSession()?->number());
    }

    #[Test]
    public function aNewSessionHasNoScene(): void
    {
        $campaign = $this->campaign();

        $campaign->startSession(new \DateTimeImmutable());

        self::assertSame([], $campaign->currentSession()?->scenes());
        self::assertNull($campaign->currentScene());
    }

    #[Test]
    public function aSceneNeedsACurrentSession(): void
    {
        $this->expectException(NoCurrentSession::class);
        $this->expectExceptionMessageIsOrContains('Start a session before starting a scene.');

        $this->campaign()->startScene('Arrival', new \DateTimeImmutable());
    }

    #[Test]
    public function scenesAreNumberedFromOneWithinTheCurrentSession(): void
    {
        $campaign = $this->campaign();
        $campaign->startSession(new \DateTimeImmutable('2026-10-06 10:00:00'));
        $at = new \DateTimeImmutable('2026-10-06 10:05:00');

        $campaign->startScene('  Arrival ', $at);
        $campaign->startScene('The gate', new \DateTimeImmutable('2026-10-06 10:30:00'));

        $scenes = $campaign->currentSession()?->scenes() ?? [];
        self::assertCount(2, $scenes);
        self::assertSame(1, $scenes[0]->number());
        self::assertSame('Arrival', $scenes[0]->title());
        self::assertEquals($at, $scenes[0]->startedAt());
        self::assertSame(2, $scenes[1]->number());
        self::assertSame('The gate', $campaign->currentScene()?->title());
        self::assertSame(2, $campaign->currentScene()->number());
    }

    #[Test]
    public function sceneNumberingRestartsInEachSessionAndEarlierScenesStay(): void
    {
        $campaign = $this->campaign();
        $campaign->startSession(new \DateTimeImmutable());
        $campaign->startScene('One', new \DateTimeImmutable());
        $campaign->startScene('Two', new \DateTimeImmutable());

        $campaign->startSession(new \DateTimeImmutable());
        self::assertNull($campaign->currentScene());

        $scene = $campaign->startScene('Again', new \DateTimeImmutable());

        self::assertSame(1, $scene->number());
        self::assertSame($scene, $campaign->currentScene());
        self::assertSame(2, $campaign->currentSession()?->number());
        self::assertCount(2, $campaign->sessions()[0]->scenes());
        self::assertCount(1, $campaign->sessions()[1]->scenes());
    }

    #[Test]
    public function aSceneTitleOfOneHundredCharactersIsAccepted(): void
    {
        $campaign = $this->campaignInSession();
        $title = str_repeat('ü', 100);

        $campaign->startScene($title, new \DateTimeImmutable());

        self::assertSame($title, $campaign->currentScene()?->title());
    }

    #[Test]
    public function aSceneTitleAboveOneHundredCharactersIsRejected(): void
    {
        $campaign = $this->campaignInSession();

        $this->expectException(InvalidSceneTitle::class);
        $this->expectExceptionMessageIsOrContains('A scene title must be at most 100 characters, got 101.');

        $campaign->startScene(str_repeat('a', 101), new \DateTimeImmutable());
    }

    #[Test]
    #[DataProvider('blankNames')]
    public function aBlankSceneTitleIsRejectedAndNoSceneIsAdded(string $title): void
    {
        $campaign = $this->campaignInSession();

        try {
            $campaign->startScene($title, new \DateTimeImmutable());
            self::fail('Expected InvalidSceneTitle.');
        } catch (InvalidSceneTitle $invalidSceneTitle) {
            self::assertSame('A scene title must not be blank.', $invalidSceneTitle->getMessage());
        }

        self::assertNull($campaign->currentScene());
    }

    #[Test]
    public function aCampaignHoldsAtMostFiveHundredSessions(): void
    {
        $campaign = $this->campaign();
        for ($i = 0; $i < 500; ++$i) {
            $campaign->startSession(new \DateTimeImmutable());
        }

        self::assertSame(500, $campaign->currentSession()?->number());

        $this->expectException(CampaignLimitReached::class);
        $this->expectExceptionMessageIsOrContains('A campaign holds at most 500 sessions.');

        $campaign->startSession(new \DateTimeImmutable());
    }

    #[Test]
    public function aSessionHoldsAtMostTwoHundredScenes(): void
    {
        $campaign = $this->campaignInSession();
        for ($i = 1; $i <= 200; ++$i) {
            $campaign->startScene('Scene '.$i, new \DateTimeImmutable());
        }

        self::assertSame(200, $campaign->currentScene()?->number());

        try {
            $campaign->startScene('One too many', new \DateTimeImmutable());
            self::fail('Expected CampaignLimitReached.');
        } catch (CampaignLimitReached $campaignLimitReached) {
            self::assertSame('A session holds at most 200 scenes.', $campaignLimitReached->getMessage());
        }

        $campaign->startSession(new \DateTimeImmutable());
        self::assertSame(1, $campaign->startScene('Fresh session', new \DateTimeImmutable())->number());
    }

    #[Test]
    public function aCampaignIsReconstitutedWithItsSessionsAndScenes(): void
    {
        $original = $this->campaignInSession();
        $original->startScene('Arrival', new \DateTimeImmutable('2026-10-06 10:05:00'));

        $campaign = Campaign::reconstitute(
            $original->id(),
            $original->ownerId(),
            $original->name(),
            $original->pinnedRelease(),
            $original->createdAt(),
            $original->sessions(),
        );

        self::assertEquals($original, $campaign);
        $campaign->startScene('Next', new \DateTimeImmutable());
        self::assertSame(2, $campaign->currentScene()?->number());
    }

    private function campaign(string $name = 'The Long Road'): Campaign
    {
        return Campaign::create(
            CampaignId::fromString(self::ID),
            'user-1',
            $name,
            PinnedRelease::of('free-journal', 1, 'Free journal'),
            new \DateTimeImmutable('2026-10-06 09:00:00'),
        );
    }

    private function campaignInSession(): Campaign
    {
        $campaign = $this->campaign();
        $campaign->startSession(new \DateTimeImmutable('2026-10-06 10:00:00'));

        return $campaign;
    }
}
