<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CreateCampaign;
use App\Play\Application\CreateCampaignHandler;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\InvalidCampaignName;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Tests\Support\Play\FixedClock;
use App\Tests\Support\Play\InMemoryCampaignRepository;
use App\Tests\Support\Play\InMemoryPublishedGameSystemReleases;
use App\Tests\Support\Play\Snapshots;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CreateCampaign::class)]
#[CoversClass(CreateCampaignHandler::class)]
final class CreateCampaignHandlerTest extends TestCase
{
    private InMemoryCampaignRepository $campaigns;
    private InMemoryPublishedGameSystemReleases $releases;
    private CreateCampaignHandler $handler;

    protected function setUp(): void
    {
        $this->campaigns = new InMemoryCampaignRepository();
        $this->releases = new InMemoryPublishedGameSystemReleases();
        $this->releases->add(Snapshots::bare('free-journal', 'Free journal', 1));
        $this->releases->add(Snapshots::bare('free-journal', 'Free journal, revised', 2));
        $this->handler = new CreateCampaignHandler($this->campaigns, $this->releases, new FixedClock('2026-10-06T09:30:00+00:00'));
    }

    #[Test]
    public function itCreatesACampaignPinnedToTheLatestRelease(): void
    {
        ($this->handler)(new CreateCampaign('campaign-1', 'user-1', '  The lost mine  ', 'free-journal'));

        $campaign = $this->campaigns->ofId(CampaignId::fromString('campaign-1'));
        self::assertNotNull($campaign);
        self::assertTrue($campaign->isOwnedBy('user-1'));
        self::assertSame('The lost mine', $campaign->name());
        self::assertSame('free-journal', $campaign->pinnedRelease()->gameSystemKey());
        self::assertSame(2, $campaign->pinnedRelease()->releaseVersion());
        self::assertSame('Free journal, revised', $campaign->pinnedRelease()->gameSystemName());
        self::assertEquals(new \DateTimeImmutable('2026-10-06T09:30:00+00:00'), $campaign->createdAt());
        self::assertSame([], $campaign->sessions());
    }

    #[Test]
    public function theCampaignStaysPinnedWhenANewerReleaseIsPublished(): void
    {
        ($this->handler)(new CreateCampaign('campaign-1', 'user-1', 'The lost mine', 'free-journal'));

        $this->releases->add(Snapshots::bare('free-journal', 'Free journal, third', 3));

        self::assertSame(2, $this->campaigns->ofId(CampaignId::fromString('campaign-1'))?->pinnedRelease()->releaseVersion());
    }

    #[Test]
    public function anUnknownGameSystemIsNotFoundAndNothingIsCreated(): void
    {
        try {
            ($this->handler)(new CreateCampaign('campaign-1', 'user-1', 'The lost mine', 'unknown'));
            self::fail('An unknown GameSystem was accepted.');
        } catch (GameSystemReleaseNotFound) {
            self::assertNull($this->campaigns->ofId(CampaignId::fromString('campaign-1')));
        }
    }

    #[Test]
    public function anInvalidNameCreatesNothing(): void
    {
        try {
            ($this->handler)(new CreateCampaign('campaign-1', 'user-1', '   ', 'free-journal'));
            self::fail('A blank name was accepted.');
        } catch (InvalidCampaignName) {
            self::assertSame([], $this->campaigns->ownedBy('user-1'));
        }
    }
}
