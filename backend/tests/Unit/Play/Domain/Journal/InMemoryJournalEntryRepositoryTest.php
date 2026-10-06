<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Journal;

use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Journal\JournalEntry;
use App\Play\Domain\Journal\JournalEntryId;
use App\Play\Domain\Journal\NoteContent;
use App\Tests\Support\Play\InMemoryJournalEntryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the JournalEntryRepository contract on the in-memory double that Application tests rely on.
 */
#[CoversClass(InMemoryJournalEntryRepository::class)]
final class InMemoryJournalEntryRepositoryTest extends TestCase
{
    private const string CAMPAIGN = '01890a5d-ac96-774b-bcce-b302099a8057';

    private const string OTHER_CAMPAIGN = '01890a5d-ac96-774b-bcce-b302099a8058';

    #[Test]
    public function itListsTheEntriesOfOneCampaignByRecordingTimeThenId(): void
    {
        $repository = new InMemoryJournalEntryRepository();
        $repository->add($this->entry('01890a5d-ac96-774b-bcce-b302099a9003', self::CAMPAIGN, '2026-10-06 10:02:00'));
        $repository->add($this->entry('01890a5d-ac96-774b-bcce-b302099a9002', self::CAMPAIGN, '2026-10-06 10:01:00'));
        $repository->add($this->entry('01890a5d-ac96-774b-bcce-b302099a9005', self::OTHER_CAMPAIGN, '2026-10-06 10:00:00'));
        $repository->add($this->entry('01890a5d-ac96-774b-bcce-b302099a9004', self::CAMPAIGN, '2026-10-06 10:01:00'));
        $repository->add($this->entry('01890a5d-ac96-774b-bcce-b302099a9001', self::CAMPAIGN, '2026-10-06 10:01:00'));

        $ids = array_map(
            static fn (JournalEntry $entry): string => $entry->id()->toString(),
            $repository->ofCampaign(CampaignId::fromString(self::CAMPAIGN)),
        );

        self::assertSame([
            '01890a5d-ac96-774b-bcce-b302099a9001',
            '01890a5d-ac96-774b-bcce-b302099a9002',
            '01890a5d-ac96-774b-bcce-b302099a9004',
            '01890a5d-ac96-774b-bcce-b302099a9003',
        ], $ids);
        self::assertSame([], $repository->ofCampaign(CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8059')));
    }

    #[Test]
    public function itRejectsADuplicateId(): void
    {
        $repository = new InMemoryJournalEntryRepository();
        $repository->add($this->entry('01890a5d-ac96-774b-bcce-b302099a9001', self::CAMPAIGN, '2026-10-06 10:00:00'));

        $this->expectException(\LogicException::class);

        $repository->add($this->entry('01890a5d-ac96-774b-bcce-b302099a9001', self::OTHER_CAMPAIGN, '2026-10-06 11:00:00'));
    }

    private function entry(string $id, string $campaignId, string $recordedAt): JournalEntry
    {
        return JournalEntry::reconstitute(
            JournalEntryId::fromString($id),
            CampaignId::fromString($campaignId),
            1,
            1,
            new \DateTimeImmutable($recordedAt),
            NoteContent::of('Entry '.$id),
        );
    }
}
