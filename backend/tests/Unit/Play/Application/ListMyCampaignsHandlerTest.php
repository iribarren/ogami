<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignSummaryView;
use App\Play\Application\ListMyCampaigns;
use App\Play\Application\ListMyCampaignsHandler;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Tests\Support\Play\InMemoryCampaignRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListMyCampaigns::class)]
#[CoversClass(ListMyCampaignsHandler::class)]
#[CoversClass(CampaignSummaryView::class)]
final class ListMyCampaignsHandlerTest extends TestCase
{
    #[Test]
    public function itListsOnlyMyCampaignsNewestFirst(): void
    {
        $campaigns = new InMemoryCampaignRepository();
        $campaigns->add($this->campaign('campaign-1', 'user-1', 'Older', '2026-10-05T10:00:00+00:00'));
        $campaigns->add($this->campaign('campaign-2', 'user-2', 'Not mine', '2026-10-06T10:00:00+00:00'));
        $campaigns->add($this->campaign('campaign-3', 'user-1', 'Newer', '2026-10-07T10:00:00+00:00'));

        $summaries = new ListMyCampaignsHandler($campaigns)(new ListMyCampaigns('user-1'));

        self::assertEquals([
            new CampaignSummaryView('campaign-3', 'Newer', 'free-journal', 'Free journal', 2, new \DateTimeImmutable('2026-10-07T10:00:00+00:00')),
            new CampaignSummaryView('campaign-1', 'Older', 'free-journal', 'Free journal', 2, new \DateTimeImmutable('2026-10-05T10:00:00+00:00')),
        ], $summaries);
    }

    #[Test]
    public function aPlayerWithoutCampaignsGetsAnEmptyList(): void
    {
        self::assertSame([], new ListMyCampaignsHandler(new InMemoryCampaignRepository())(new ListMyCampaigns('user-1')));
    }

    private function campaign(string $id, string $ownerId, string $name, string $createdAt): Campaign
    {
        return Campaign::create(CampaignId::fromString($id), $ownerId, $name, PinnedRelease::of('free-journal', 2, 'Free journal'), new \DateTimeImmutable($createdAt));
    }
}
