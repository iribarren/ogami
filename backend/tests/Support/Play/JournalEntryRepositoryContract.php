<?php

declare(strict_types=1);

namespace App\Tests\Support\Play;

use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Journal\JournalEntry;
use App\Play\Domain\Journal\JournalEntryAlreadyExists;
use App\Play\Domain\Journal\JournalEntryContent;
use App\Play\Domain\Journal\JournalEntryContents;
use App\Play\Domain\Journal\JournalEntryId;
use App\Play\Domain\Journal\JournalEntryRepository;
use App\Play\Domain\Journal\NoteContent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The JournalEntryRepository contract, shared by the in-memory double and the Doctrine adapter.
 * Ids are UUIDs because the database stores them as such.
 *
 * The using test class provides the repository, givenCampaign() (an entry belongs to an existing
 * campaign in the database) and forgetLoaded() (what a new request would see).
 */
trait JournalEntryRepositoryContract
{
    private const string CAMPAIGN = '01890a5d-ac96-774b-bcce-b302099a8057';
    private const string OTHER_CAMPAIGN = '01890a5d-ac96-774b-bcce-b302099a8058';

    abstract protected function entries(): JournalEntryRepository;

    abstract protected function givenCampaign(string $campaignId): void;

    abstract protected function forgetLoaded(): void;

    /**
     * One content of every kind, in its canonical stored shape.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function contentOfEveryKind(): iterable
    {
        yield 'note' => [['kind' => 'note', 'text' => 'The gate is open. Ünïcödé — "quoted".']];
        yield 'roll' => [[
            'kind' => 'roll',
            'expression' => '4d6kh3+1',
            'total' => 15,
            'groups' => [[
                'notation' => '4d6kh3',
                'sides' => 6,
                'dice' => [['value' => 6, 'kept' => true], ['value' => 1, 'kept' => false], ['value' => 4, 'kept' => true], ['value' => 4, 'kept' => true]],
                'subtotal' => 14,
            ]],
        ]];
        yield 'oracle table' => [[
            'kind' => 'oracle-table',
            'oracleKey' => 'weather',
            'oracleName' => 'Weather',
            'steps' => [
                ['tableKey' => 'weather', 'tableName' => 'Weather', 'dice' => '1d6', 'total' => 5, 'text' => 'Storm', 'nestedTableKey' => 'storm-kind'],
                ['tableKey' => 'storm-kind', 'tableName' => 'Storm kind', 'dice' => '1d3', 'total' => 3, 'text' => 'Hail', 'nestedTableKey' => null],
            ],
        ]];
        yield 'likelihood with question and chaos' => [[
            'kind' => 'likelihood',
            'oracleKey' => 'fate',
            'oracleName' => 'Fate question',
            'question' => 'Is the mine guarded?',
            'answer' => 'exceptional_yes',
            'roll' => 7,
            'sides' => 100,
            'effectiveTarget' => 55,
            'likelihood' => 'even',
            'likelihoodLabel' => '50/50',
            'chaosFactor' => 6,
        ]];
        yield 'likelihood without question or chaos' => [[
            'kind' => 'likelihood',
            'oracleKey' => 'fate',
            'oracleName' => 'Fate question',
            'question' => null,
            'answer' => 'no',
            'roll' => 80,
            'sides' => 100,
            'effectiveTarget' => 50,
            'likelihood' => 'even',
            'likelihoodLabel' => '50/50',
            'chaosFactor' => null,
        ]];
    }

    /**
     * @param array<string, mixed> $content
     */
    #[Test]
    #[DataProvider('contentOfEveryKind')]
    public function itKeepsAnEntryOfEveryKindAsRecorded(array $content): void
    {
        $this->givenCampaign(self::CAMPAIGN);
        $entry = JournalEntry::reconstitute(
            JournalEntryId::fromString('01890a5d-ac96-774b-bcce-b302099a9001'),
            CampaignId::fromString(self::CAMPAIGN),
            3,
            7,
            new \DateTimeImmutable('2026-10-06T10:00:00+02:00'),
            JournalEntryContents::fromArray($content),
        );

        $this->entries()->add($entry);
        $this->forgetLoaded();
        $loaded = $this->entries()->ofId(JournalEntryId::fromString('01890a5d-ac96-774b-bcce-b302099a9001'));

        self::assertNotNull($loaded);
        self::assertEquals($entry, $loaded);
        self::assertSame(self::CAMPAIGN, $loaded->campaignId()->toString());
        self::assertSame(3, $loaded->sessionNumber());
        self::assertSame(7, $loaded->sceneNumber());
        self::assertSame(new \DateTimeImmutable('2026-10-06T08:00:00+00:00')->getTimestamp(), $loaded->recordedAt()->getTimestamp());
        // Same values in the canonical key order, whatever order the storage keeps keys in.
        self::assertSame($content, $loaded->content()->toArray());
        self::assertEquals([$entry], $this->entries()->ofCampaign(CampaignId::fromString(self::CAMPAIGN)));
    }

    #[Test]
    public function itListsTheEntriesOfOneCampaignByRecordingTimeThenId(): void
    {
        $this->givenCampaign(self::CAMPAIGN);
        $this->givenCampaign(self::OTHER_CAMPAIGN);
        $this->entries()->add($this->entry('01890a5d-ac96-774b-bcce-b302099a9003', self::CAMPAIGN, '2026-10-06 10:02:00'));
        $this->entries()->add($this->entry('01890a5d-ac96-774b-bcce-b302099a9002', self::CAMPAIGN, '2026-10-06 10:01:00'));
        $this->entries()->add($this->entry('01890a5d-ac96-774b-bcce-b302099a9005', self::OTHER_CAMPAIGN, '2026-10-06 10:00:00'));
        $this->entries()->add($this->entry('01890a5d-ac96-774b-bcce-b302099a9004', self::CAMPAIGN, '2026-10-06 10:01:00'));
        $this->entries()->add($this->entry('01890a5d-ac96-774b-bcce-b302099a9001', self::CAMPAIGN, '2026-10-06 10:01:00'));
        $this->forgetLoaded();

        $ids = array_map(
            static fn (JournalEntry $entry): string => $entry->id()->toString(),
            $this->entries()->ofCampaign(CampaignId::fromString(self::CAMPAIGN)),
        );

        self::assertSame([
            '01890a5d-ac96-774b-bcce-b302099a9001',
            '01890a5d-ac96-774b-bcce-b302099a9002',
            '01890a5d-ac96-774b-bcce-b302099a9004',
            '01890a5d-ac96-774b-bcce-b302099a9003',
        ], $ids);
        self::assertSame([], $this->entries()->ofCampaign(CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8059')));
        self::assertSame([], $this->entries()->ofCampaign(CampaignId::fromString('not-a-uuid')));
    }

    #[Test]
    public function itRejectsADuplicateId(): void
    {
        $this->givenCampaign(self::CAMPAIGN);
        $this->givenCampaign(self::OTHER_CAMPAIGN);
        $this->entries()->add($this->entry('01890a5d-ac96-774b-bcce-b302099a9001', self::CAMPAIGN, '2026-10-06 10:00:00'));
        $this->forgetLoaded();

        $this->expectException(JournalEntryAlreadyExists::class);
        $this->expectExceptionMessageIsOrContains('A journal entry with id "01890a5d-ac96-774b-bcce-b302099a9001" already exists.');

        $this->entries()->add($this->entry('01890a5d-ac96-774b-bcce-b302099a9001', self::OTHER_CAMPAIGN, '2026-10-06 11:00:00'));
    }

    #[Test]
    public function itFindsAnEntryById(): void
    {
        $this->givenCampaign(self::CAMPAIGN);
        $entry = $this->entry('01890a5d-ac96-774b-bcce-b302099a9001', self::CAMPAIGN, '2026-10-06 10:00:00');
        $this->entries()->add($entry);
        $this->forgetLoaded();

        self::assertEquals($entry, $this->entries()->ofId(JournalEntryId::fromString('01890a5d-ac96-774b-bcce-b302099a9001')));
        self::assertNull($this->entries()->ofId(JournalEntryId::fromString('01890a5d-ac96-774b-bcce-b302099a9002')));
        self::assertNull($this->entries()->ofId(JournalEntryId::fromString('not-a-uuid')));
    }

    private function entry(string $id, string $campaignId, string $recordedAt, ?JournalEntryContent $content = null): JournalEntry
    {
        return JournalEntry::reconstitute(
            JournalEntryId::fromString($id),
            CampaignId::fromString($campaignId),
            1,
            1,
            new \DateTimeImmutable($recordedAt, new \DateTimeZone('UTC')),
            $content ?? NoteContent::of('Entry '.$id),
        );
    }
}
