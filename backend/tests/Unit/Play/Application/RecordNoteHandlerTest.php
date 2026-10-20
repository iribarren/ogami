<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignJournal;
use App\Play\Application\CampaignNotFound;
use App\Play\Application\RecordNote;
use App\Play\Application\RecordNoteHandler;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\NoCurrentScene;
use App\Play\Domain\Journal\ChoiceContent;
use App\Play\Domain\Journal\InvalidJournalEntryContent;
use App\Play\Domain\Journal\InvalidJournalEntryId;
use App\Play\Domain\Journal\InvalidNoteAttachment;
use App\Play\Domain\Journal\JournalEntry;
use App\Play\Domain\Journal\JournalEntryAlreadyExists;
use App\Play\Domain\Journal\JournalEntryContent;
use App\Play\Domain\Journal\JournalEntryId;
use App\Play\Domain\Journal\LikelihoodContent;
use App\Play\Domain\Journal\NoteContent;
use App\Play\Domain\Journal\OracleTableContent;
use App\Play\Domain\Journal\RollContent;
use App\Randomness\Domain\DiceExpression;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use App\Tests\Unit\Play\Domain\Journal\CheckContentTest;
use App\Tests\Unit\Play\Domain\Journal\LikelihoodContentTest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(RecordNote::class)]
#[CoversClass(RecordNoteHandler::class)]
#[CoversClass(CampaignJournal::class)]
#[CoversClass(InvalidNoteAttachment::class)]
final class RecordNoteHandlerTest extends JournalTestCase
{
    private RecordNoteHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new RecordNoteHandler($this->journal);
    }

    #[Test]
    public function itRecordsTheNoteInTheCurrentScene(): void
    {
        ($this->handler)(new RecordNote('entry-1', 'campaign-1', 'user-1', '  The gate is shut.  '));

        $entry = $this->onlyEntryOf('campaign-1');
        self::assertSame('entry-1', $entry->id()->toString());
        self::assertSame(1, $entry->sessionNumber());
        self::assertSame(2, $entry->sceneNumber());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $entry->recordedAt());
        self::assertEquals(NoteContent::of('The gate is shut.'), $entry->content());
    }

    #[Test]
    public function anotherPlayersCampaignIsNotFoundAndNothingIsRecorded(): void
    {
        try {
            ($this->handler)(new RecordNote('entry-1', 'campaign-1', 'user-2', 'Mine now.'));
            self::fail('Another player recorded a note.');
        } catch (CampaignNotFound) {
            self::assertSame([], $this->journalOf('campaign-1'));
        }
    }

    #[Test]
    public function aCampaignWithoutCurrentSceneRecordsNothing(): void
    {
        try {
            ($this->handler)(new RecordNote('entry-1', 'campaign-2', 'user-1', 'The gate is shut.'));
            self::fail('A note was recorded without a scene.');
        } catch (NoCurrentScene) {
            self::assertSame([], $this->journalOf('campaign-2'));
        }
    }

    #[Test]
    public function aBlankNoteRecordsNothing(): void
    {
        try {
            ($this->handler)(new RecordNote('entry-1', 'campaign-1', 'user-1', '   '));
            self::fail('A blank note was recorded.');
        } catch (InvalidJournalEntryContent) {
            self::assertSame([], $this->journalOf('campaign-1'));
        }
    }

    #[Test]
    public function aBlankEntryIdIsRejected(): void
    {
        $this->expectException(InvalidJournalEntryId::class);

        ($this->handler)(new RecordNote(' ', 'campaign-1', 'user-1', 'The gate is shut.'));
    }

    #[Test]
    public function anEntryIdAlreadyTakenIsRejectedAndTheFirstEntryIsKept(): void
    {
        ($this->handler)(new RecordNote('entry-1', 'campaign-1', 'user-1', 'The gate is shut.'));

        try {
            ($this->handler)(new RecordNote('entry-1', 'campaign-1', 'user-1', 'The gate is open.'));
            self::fail('An entry id already taken was accepted.');
        } catch (JournalEntryAlreadyExists $exception) {
            self::assertSame('A journal entry with id "entry-1" already exists.', $exception->getMessage());
        }
    }

    #[Test]
    public function itAttachesTheResultsOfTheCurrentSceneInThePickOrder(): void
    {
        $this->seed('roll-1', 1, 2, $this->roll());
        $this->seed('roll-2', 1, 2, $this->roll());

        ($this->handler)(new RecordNote('entry-1', 'campaign-1', 'user-1', 'Two rolls.', ['roll-2', 'roll-1']));

        $note = $this->entries->ofId(JournalEntryId::fromString('entry-1'));
        self::assertInstanceOf(JournalEntry::class, $note);
        self::assertEquals(NoteContent::of('Two rolls.', ['roll-2', 'roll-1']), $note->content());
    }

    #[Test]
    public function aResultCanBeAttachedToSeveralNotes(): void
    {
        $this->seed('roll-1', 1, 2, $this->roll());

        ($this->handler)(new RecordNote('entry-1', 'campaign-1', 'user-1', 'First.', ['roll-1']));
        ($this->handler)(new RecordNote('entry-2', 'campaign-1', 'user-1', 'Second.', ['roll-1']));

        self::assertCount(3, $this->journalOf('campaign-1'));
    }

    #[Test]
    public function aResultOfEveryAttachableKindIsAccepted(): void
    {
        $this->seed('roll-1', 1, 2, $this->roll());
        $this->seed('oracle-1', 1, 2, OracleTableContent::fromArray($this->oracleTable(null)));

        ($this->handler)(new RecordNote('entry-1', 'campaign-1', 'user-1', 'Both.', ['roll-1', 'oracle-1']));

        self::assertCount(3, $this->journalOf('campaign-1'));
    }

    #[Test]
    public function aCheckAndALikelihoodResultCanBeAttached(): void
    {
        $this->seed('check-1', 1, 2, CheckContentTest::edgeCheck());
        $this->seed('likelihood-1', 1, 2, LikelihoodContent::fromAnswer('fate', 'Fate', 'Is the gate open?', LikelihoodContentTest::likelyAnswer()));

        ($this->handler)(new RecordNote('entry-1', 'campaign-1', 'user-1', 'Both.', ['likelihood-1', 'check-1']));

        $note = $this->entries->ofId(JournalEntryId::fromString('entry-1'));
        self::assertInstanceOf(JournalEntry::class, $note);
        self::assertEquals(NoteContent::of('Both.', ['likelihood-1', 'check-1']), $note->content());
    }

    /**
     * @param callable(): list<string> $attachments
     */
    #[Test]
    #[DataProvider('refusedAttachments')]
    public function anInvalidAttachmentRecordsNothing(callable $attachments, string $message): void
    {
        $this->seed('roll-1', 1, 2, $this->roll());
        $this->seed('roll-old', 1, 1, $this->roll());
        $this->seed('roll-session-2', 2, 2, $this->roll());
        $this->seed('note-1', 1, 2, NoteContent::of('A note.'));
        $this->seed('choice-1', 1, 2, ChoiceContent::of('Question?', 'yes', 'Yes'));
        $this->seed('oracle-event', 1, 2, OracleTableContent::fromArray($this->oracleTable('question-1')));
        $this->seedInAnotherCampaign('roll-other', $this->roll());
        $before = $this->journalOf('campaign-1');

        try {
            ($this->handler)(new RecordNote('entry-1', 'campaign-1', 'user-1', 'A note.', $attachments()));
            self::fail('An invalid attachment was accepted.');
        } catch (InvalidNoteAttachment $exception) {
            self::assertSame($message, $exception->getMessage());
        }

        self::assertEquals($before, $this->journalOf('campaign-1'));
    }

    /**
     * @return iterable<string, array{callable(): list<string>, string}>
     */
    public static function refusedAttachments(): iterable
    {
        yield 'unknown id' => [static fn (): array => ['roll-1', 'nope'], 'Journal entry "nope" cannot be attached: it is not an entry of this campaign.'];
        yield 'not a uuid' => [static fn (): array => ['not-a-uuid'], 'Journal entry "not-a-uuid" cannot be attached: it is not an entry of this campaign.'];
        yield 'another campaign' => [static fn (): array => ['roll-other'], 'Journal entry "roll-other" cannot be attached: it is not an entry of this campaign.'];
        yield 'another scene' => [static fn (): array => ['roll-old'], 'Journal entry "roll-old" cannot be attached: it was recorded in another scene than the current one.'];
        yield 'another session' => [static fn (): array => ['roll-session-2'], 'Journal entry "roll-session-2" cannot be attached: it was recorded in another scene than the current one.'];
        yield 'a note' => [static fn (): array => ['note-1'], 'Journal entry "note-1" cannot be attached: a note result cannot be attached.'];
        yield 'a choice' => [static fn (): array => ['choice-1'], 'Journal entry "choice-1" cannot be attached: a choice result cannot be attached.'];
        yield 'a random event result' => [static fn (): array => ['oracle-event'], 'Journal entry "oracle-event" cannot be attached: a random event result already belongs to its question.'];
        yield 'a duplicate' => [static fn (): array => ['roll-1', 'roll-1'], 'Journal entry "roll-1" is attached twice.'];
        yield 'a blank id' => [static fn (): array => [' '], 'An attached journal entry id must not be blank.'];
        yield 'more than twenty' => [static fn (): array => array_map(static fn (int $n): string => 'roll-'.$n, range(1, 21)), 'A note attaches at most 20 results, 21 given.'];
    }

    #[Test]
    public function aCampaignWithoutCurrentSceneStillReportsTheMissingScene(): void
    {
        $this->expectException(NoCurrentScene::class);

        ($this->handler)(new RecordNote('entry-1', 'campaign-2', 'user-1', 'A note.', ['roll-1']));
    }

    private function roll(): RollContent
    {
        return RollContent::fromRoll(DiceExpression::fromString('2d6')->roll(new ScriptedRandomNumberGenerator(3, 4)));
    }

    /**
     * @return array<string, mixed>
     */
    private function oracleTable(?string $forEntry): array
    {
        return [
            'kind' => 'oracle-table',
            'oracleKey' => 'weather',
            'oracleName' => 'Weather',
            'forEntry' => $forEntry,
            'steps' => [['tableKey' => 'weather', 'tableName' => 'Weather', 'dice' => '1d6', 'total' => 3, 'text' => 'Rain', 'nestedTableKey' => null, 'sceneType' => null]],
        ];
    }

    private function seed(string $id, int $session, int $scene, JournalEntryContent $content): void
    {
        $this->entries->add(JournalEntry::reconstitute(JournalEntryId::fromString($id), CampaignId::fromString('campaign-1'), $session, $scene, new \DateTimeImmutable(self::NOW), $content));
    }

    private function seedInAnotherCampaign(string $id, JournalEntryContent $content): void
    {
        $this->entries->add(JournalEntry::reconstitute(JournalEntryId::fromString($id), CampaignId::fromString('campaign-2'), 1, 2, new \DateTimeImmutable(self::NOW), $content));
    }
}
