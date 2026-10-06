<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Tests\Support\Play\InMemoryCampaignRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the CampaignRepository contract on the in-memory double that Application tests rely on.
 */
#[CoversClass(InMemoryCampaignRepository::class)]
final class InMemoryCampaignRepositoryTest extends TestCase
{
    #[Test]
    public function itFindsACampaignById(): void
    {
        $repository = new InMemoryCampaignRepository();
        $campaign = $this->campaign('01890a5d-ac96-774b-bcce-b302099a8057', 'user-1', '2026-10-06');

        $repository->add($campaign);

        self::assertEquals($campaign, $repository->ofId(CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057')));
        self::assertNull($repository->ofId(CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8058')));
    }

    #[Test]
    public function itRejectsADuplicateId(): void
    {
        $repository = new InMemoryCampaignRepository();
        $repository->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8057', 'user-1', '2026-10-06'));

        $this->expectException(\LogicException::class);

        $repository->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8057', 'user-2', '2026-10-07'));
    }

    #[Test]
    public function itListsOnlyTheOwnersCampaignsNewestFirst(): void
    {
        $repository = new InMemoryCampaignRepository();
        $repository->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8001', 'user-1', '2026-10-01'));
        $repository->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8003', 'user-1', '2026-10-03'));
        $repository->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8002', 'user-2', '2026-10-02'));
        $repository->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8004', 'user-1', '2026-10-02'));

        $ids = array_map(static fn (Campaign $campaign): string => $campaign->id()->toString(), $repository->ownedBy('user-1'));

        self::assertSame([
            '01890a5d-ac96-774b-bcce-b302099a8003',
            '01890a5d-ac96-774b-bcce-b302099a8004',
            '01890a5d-ac96-774b-bcce-b302099a8001',
        ], $ids);
        self::assertSame([], $repository->ownedBy('user-3'));
    }

    #[Test]
    public function campaignsWithTheSameCreationTimeAreListedByIdDescending(): void
    {
        $repository = new InMemoryCampaignRepository();
        $repository->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8001', 'user-1', '2026-10-02 10:00:00'));
        $repository->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8003', 'user-1', '2026-10-02 10:00:00'));
        $repository->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8002', 'user-1', '2026-10-02 10:00:00'));

        $ids = array_map(static fn (Campaign $campaign): string => $campaign->id()->toString(), $repository->ownedBy('user-1'));

        self::assertSame([
            '01890a5d-ac96-774b-bcce-b302099a8003',
            '01890a5d-ac96-774b-bcce-b302099a8002',
            '01890a5d-ac96-774b-bcce-b302099a8001',
        ], $ids);
    }

    #[Test]
    public function aChangeIsNotKeptUntilTheCampaignIsSaved(): void
    {
        $repository = new InMemoryCampaignRepository();
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $campaign = $this->campaign($id->toString(), 'user-1', '2026-10-06');
        $repository->add($campaign);

        $campaign->startSession(new \DateTimeImmutable('2026-10-06 11:00:00'));
        $loaded = $repository->ofId($id);
        self::assertNotNull($loaded);
        $loaded->startSession(new \DateTimeImmutable('2026-10-06 11:00:00'));
        $repository->ownedBy('user-1')[0]->startSession(new \DateTimeImmutable('2026-10-06 11:00:00'));

        self::assertNull($repository->ofId($id)?->currentSession());
    }

    #[Test]
    public function savingKeepsTheChangedCampaign(): void
    {
        $repository = new InMemoryCampaignRepository();
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $repository->add($this->campaign($id->toString(), 'user-1', '2026-10-06'));

        $campaign = $repository->ofId($id);
        self::assertNotNull($campaign);
        $campaign->startSession(new \DateTimeImmutable('2026-10-06 11:00:00'));
        $campaign->startScene('Arrival', new \DateTimeImmutable('2026-10-06 11:05:00'));
        $repository->save($campaign);
        $campaign->startSession(new \DateTimeImmutable('2026-10-06 12:00:00'));

        $saved = $repository->ofId($id);
        self::assertSame(1, $saved?->currentSession()?->number());
        self::assertSame('Arrival', $saved->currentScene()?->title());
    }

    private function campaign(string $id, string $ownerId, string $createdAt): Campaign
    {
        return Campaign::create(
            CampaignId::fromString($id),
            $ownerId,
            'Campaign '.$id,
            PinnedRelease::of('free-journal', 1, 'Free journal'),
            new \DateTimeImmutable($createdAt),
        );
    }
}
