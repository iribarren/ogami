<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Journal;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\NoCurrentScene;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\Journal\JournalEntry;
use App\Play\Domain\Journal\JournalEntryId;
use App\Play\Domain\Journal\NoteContent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(JournalEntry::class)]
final class JournalEntryTest extends TestCase
{
    private const string ENTRY_ID = '01890a5d-ac96-774b-bcce-b302099a9001';

    #[Test]
    public function anEntryIsRecordedInTheCurrentSessionAndScene(): void
    {
        $campaign = $this->campaign();
        $campaign->startSession(new \DateTimeImmutable('2026-10-06 10:00:00'));
        $campaign->startScene('Arrival', new \DateTimeImmutable('2026-10-06 10:05:00'));
        $campaign->startSession(new \DateTimeImmutable('2026-10-07 10:00:00'));
        $campaign->startScene('The gate', new \DateTimeImmutable('2026-10-07 10:05:00'));
        $campaign->startScene('The hall', new \DateTimeImmutable('2026-10-07 10:30:00'));
        $content = NoteContent::of('The hall is empty.');
        $recordedAt = new \DateTimeImmutable('2026-10-07 10:31:00');

        $entry = JournalEntry::record(JournalEntryId::fromString(self::ENTRY_ID), $campaign, $content, $recordedAt);

        self::assertSame(self::ENTRY_ID, $entry->id()->toString());
        self::assertTrue($campaign->id()->equals($entry->campaignId()));
        self::assertSame(2, $entry->sessionNumber());
        self::assertSame(2, $entry->sceneNumber());
        self::assertSame($recordedAt, $entry->recordedAt());
        self::assertSame($content, $entry->content());
    }

    #[Test]
    public function nothingIsRecordedBeforeTheFirstSession(): void
    {
        $this->expectException(NoCurrentScene::class);

        JournalEntry::record(JournalEntryId::fromString(self::ENTRY_ID), $this->campaign(), NoteContent::of('Hello'), new \DateTimeImmutable());
    }

    #[Test]
    public function nothingIsRecordedInASessionWithoutAScene(): void
    {
        $campaign = $this->campaign();
        $campaign->startSession(new \DateTimeImmutable('2026-10-06 10:00:00'));
        $campaign->startScene('Arrival', new \DateTimeImmutable('2026-10-06 10:05:00'));
        $campaign->startSession(new \DateTimeImmutable('2026-10-07 10:00:00'));

        $this->expectException(NoCurrentScene::class);

        JournalEntry::record(JournalEntryId::fromString(self::ENTRY_ID), $campaign, NoteContent::of('Hello'), new \DateTimeImmutable());
    }

    #[Test]
    public function nothingIsRecordedOnceTheSessionHasEnded(): void
    {
        $campaign = $this->campaign();
        $campaign->startSession(new \DateTimeImmutable('2026-10-06 10:00:00'));
        $campaign->startScene('Arrival', new \DateTimeImmutable('2026-10-06 10:05:00'));
        $campaign->endSession(new \DateTimeImmutable('2026-10-06 12:00:00'));

        $this->expectException(NoCurrentScene::class);

        JournalEntry::record(JournalEntryId::fromString(self::ENTRY_ID), $campaign, NoteContent::of('Hello'), new \DateTimeImmutable());
    }

    #[Test]
    public function aStoredEntryIsRebuiltAsIs(): void
    {
        $content = NoteContent::of('Hello');
        $recordedAt = new \DateTimeImmutable('2026-10-06 10:00:00');

        $entry = JournalEntry::reconstitute(
            JournalEntryId::fromString(self::ENTRY_ID),
            CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057'),
            3,
            7,
            $recordedAt,
            $content,
        );

        self::assertSame(self::ENTRY_ID, $entry->id()->toString());
        self::assertSame('01890a5d-ac96-774b-bcce-b302099a8057', $entry->campaignId()->toString());
        self::assertSame(3, $entry->sessionNumber());
        self::assertSame(7, $entry->sceneNumber());
        self::assertSame($recordedAt, $entry->recordedAt());
        self::assertSame($content, $entry->content());
    }

    private function campaign(): Campaign
    {
        return Campaign::create(
            CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057'),
            'user-1',
            'The Long Road',
            PinnedRelease::of('free-journal', 1, 'Free journal'),
            new \DateTimeImmutable('2026-10-06 09:00:00'),
        );
    }
}
