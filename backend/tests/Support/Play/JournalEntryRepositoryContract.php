<?php

declare(strict_types=1);

namespace App\Tests\Support\Play;

use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Journal\FlowStepSnapshot;
use App\Play\Domain\Journal\JournalEntry;
use App\Play\Domain\Journal\JournalEntryAlreadyExists;
use App\Play\Domain\Journal\JournalEntryContent;
use App\Play\Domain\Journal\JournalEntryContents;
use App\Play\Domain\Journal\JournalEntryId;
use App\Play\Domain\Journal\JournalEntryRepository;
use App\Play\Domain\Journal\NoteContent;
use App\Play\Domain\Journal\OracleTableContent;
use App\Play\Domain\Journal\RandomEventAlreadyRolled;
use App\Tests\Unit\Play\Domain\Journal\OracleTableContentTest;
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
        yield 'note' => [['kind' => 'note', 'text' => 'The gate is open. Ünïcödé — "quoted".', 'attachments' => []]];
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
            'forEntry' => null,
            'steps' => [
                ['tableKey' => 'weather', 'tableName' => 'Weather', 'dice' => '1d6', 'total' => 5, 'text' => 'Storm', 'nestedTableKey' => 'storm-kind', 'sceneType' => null],
                ['tableKey' => 'storm-kind', 'tableName' => 'Storm kind', 'dice' => '1d3', 'total' => 3, 'text' => 'Hail', 'nestedTableKey' => null, 'sceneType' => 'getaway'],
            ],
        ]];
        yield 'oracle table answering a random event' => [[
            'kind' => 'oracle-table',
            'oracleKey' => 'weather',
            'oracleName' => 'Weather',
            'forEntry' => '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f8001',
            'steps' => [
                ['tableKey' => 'weather', 'tableName' => 'Weather', 'dice' => '1d6', 'total' => 2, 'text' => 'Clear', 'nestedTableKey' => null, 'sceneType' => null],
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
            'randomEvent' => 'random-event-focus',
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
            'randomEvent' => null,
        ]];
        yield 'choice' => [['kind' => 'choice', 'question' => 'Did you gain an edge?', 'optionKey' => 'yes', 'label' => 'Yes']];
        yield 'pick' => [['kind' => 'pick', 'of' => 'npc', 'tags' => ['rival', 'ally'], 'picked' => [['id' => '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7001', 'name' => 'Mira Vane'], ['id' => '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7002', 'name' => 'Brann']]]];
        $check = [
            'kind' => 'check',
            'characterId' => '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7001',
            'characterName' => 'Vi',
            'checkKey' => 'skill',
            'checkLabel' => 'Skill check',
            'expression' => '2d6+1',
            'groups' => [['notation' => '2d6', 'sides' => 6, 'dice' => [['value' => 5, 'kept' => true], ['value' => 4, 'kept' => true]], 'subtotal' => 9]],
            'diceTotal' => 10,
            'modifier' => 5,
            'situational' => -2,
            'difficulty' => 13,
            'total' => 13,
            'bandKey' => 'success',
            'bandLabel' => 'Success',
        ];
        yield 'check' => [$check];
        yield 'check without difficulty' => [array_replace($check, ['difficulty' => null])];
        yield 'pick of nothing' => [['kind' => 'pick', 'of' => 'thread', 'tags' => [], 'picked' => []]];
        yield 'sheet change' => [[
            'kind' => 'sheet-change',
            'characterId' => '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7001',
            'characterName' => 'Vi',
            'changes' => [
                ['field' => 'sanity', 'before' => 65, 'after' => 61, 'dice' => ['expression' => '1d6', 'total' => 4]],
                ['field' => 'hp', 'before' => 12, 'after' => 11, 'dice' => null],
            ],
        ]];
        yield 'flow outcome' => [[
            'kind' => 'flowOutcome',
            'notes' => [
                ['kind' => 'band', 'label' => 'Strong hit'],
                ['kind' => 'tracker', 'key' => 'threat', 'name' => 'Threat', 'from' => 3, 'to' => 4],
                ['kind' => 'switch', 'from' => 'Chase', 'to' => 'Combat'],
                ['kind' => 'nextScene', 'sceneType' => 'Briefing'],
                ['kind' => 'phaseEnd', 'phase' => 'Heist'],
            ],
        ]];
    }

    #[Test]
    public function itKeepsTheFlowStepThatRecordedAnEntryAndAnEntryWithoutOneHasNone(): void
    {
        $this->givenCampaign(self::CAMPAIGN);
        $stepped = JournalEntry::reconstitute(
            JournalEntryId::fromString('01890a5d-ac96-774b-bcce-b302099a9001'),
            CampaignId::fromString(self::CAMPAIGN),
            1,
            2,
            new \DateTimeImmutable('2026-10-06T10:00:00+00:00'),
            NoteContent::of('Through the roof'),
            FlowStepSnapshot::of('plan', 'The plan', 'How do you get in? Heat is 3.'),
        );
        $this->entries()->add($stepped);
        $this->entries()->add($this->entry('01890a5d-ac96-774b-bcce-b302099a9002', self::CAMPAIGN, '2026-10-06 10:01:00'));
        $this->forgetLoaded();

        $loaded = $this->entries()->ofId($stepped->id());
        self::assertEquals($stepped, $loaded);
        self::assertSame(['key' => 'plan', 'title' => 'The plan', 'prompt' => 'How do you get in? Heat is 3.'], $loaded?->flowStep()?->toArray());
        self::assertNull($this->entries()->ofId(JournalEntryId::fromString('01890a5d-ac96-774b-bcce-b302099a9002'))?->flowStep());
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
    public function itRejectsASecondRollForTheSameRandomEventQuestion(): void
    {
        $this->givenCampaign(self::CAMPAIGN);
        $roll = static fn (?string $forEntry): OracleTableContent => OracleTableContent::fromResult('weather', 'Weather', OracleTableContentTest::stormResult(), [], $forEntry);
        $this->entries()->add($this->entry('01890a5d-ac96-774b-bcce-b302099a9001', self::CAMPAIGN, '2026-10-06 10:00:00', $roll('01890a5d-ac96-774b-bcce-b302099a9000')));
        // Rolls for no question, and for another one, are not limited.
        $this->entries()->add($this->entry('01890a5d-ac96-774b-bcce-b302099a9002', self::CAMPAIGN, '2026-10-06 10:01:00', $roll(null)));
        $this->entries()->add($this->entry('01890a5d-ac96-774b-bcce-b302099a9003', self::CAMPAIGN, '2026-10-06 10:02:00', $roll(null)));
        $this->entries()->add($this->entry('01890a5d-ac96-774b-bcce-b302099a9004', self::CAMPAIGN, '2026-10-06 10:03:00', $roll('01890a5d-ac96-774b-bcce-b302099a9005')));
        $this->forgetLoaded();

        try {
            $this->entries()->add($this->entry('01890a5d-ac96-774b-bcce-b302099a9006', self::CAMPAIGN, '2026-10-06 10:04:00', $roll('01890a5d-ac96-774b-bcce-b302099a9000')));
            self::fail('A random event was rolled twice.');
        } catch (RandomEventAlreadyRolled $exception) {
            self::assertSame('The random event of journal entry "01890a5d-ac96-774b-bcce-b302099a9000" was already rolled.', $exception->getMessage());
        }
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
