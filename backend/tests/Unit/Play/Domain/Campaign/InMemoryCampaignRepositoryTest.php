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

        self::assertSame($campaign, $repository->ofId(CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057')));
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
    public function savingKeepsTheChangedCampaign(): void
    {
        $repository = new InMemoryCampaignRepository();
        $campaign = $this->campaign('01890a5d-ac96-774b-bcce-b302099a8057', 'user-1', '2026-10-06');
        $repository->add($campaign);

        $campaign->startSession(new \DateTimeImmutable());
        $repository->save($campaign);

        self::assertSame(1, $repository->ofId($campaign->id())?->currentSession()?->number());
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
