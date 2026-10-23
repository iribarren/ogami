<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignNotFound;
use App\Play\Application\OwnedCampaigns;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Tests\Support\Play\InMemoryCampaignRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The one place that says whose campaign a player can reach. The handlers that start with it
 * do not repeat the rule in their own tests.
 */
#[CoversClass(OwnedCampaigns::class)]
#[CoversClass(CampaignNotFound::class)]
final class OwnedCampaignsTest extends TestCase
{
    private OwnedCampaigns $ownedCampaigns;

    protected function setUp(): void
    {
        $campaigns = new InMemoryCampaignRepository();
        $campaigns->add(Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'The lost mine', PinnedRelease::of('free-journal', 1, 'Free journal'), new \DateTimeImmutable('2026-10-10T09:00:00+00:00')));
        $this->ownedCampaigns = new OwnedCampaigns($campaigns);
    }

    #[Test]
    public function theOwnerGetsTheirCampaign(): void
    {
        $campaign = $this->ownedCampaigns->get('campaign-1', 'user-1');

        self::assertSame('campaign-1', $campaign->id()->toString());
        self::assertSame('The lost mine', $campaign->name());
    }

    #[Test]
    public function anotherPlayersCampaignIsNotFound(): void
    {
        $this->expectException(CampaignNotFound::class);
        $this->expectExceptionMessageIsOrContains('Campaign "campaign-1" not found.');

        $this->ownedCampaigns->get('campaign-1', 'user-2');
    }

    #[Test]
    #[DataProvider('unreachableIds')]
    public function anUnknownOrMalformedCampaignIdIsNotFound(string $id): void
    {
        $this->expectException(CampaignNotFound::class);

        $this->ownedCampaigns->get($id, 'user-1');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreachableIds(): iterable
    {
        yield 'unknown' => ['campaign-9'];
        yield 'blank' => [' '];
    }
}
