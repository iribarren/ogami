<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignJournal;
use App\Play\Application\CampaignNotFound;
use App\Play\Application\RecordNote;
use App\Play\Application\RecordNoteHandler;
use App\Play\Domain\Campaign\NoCurrentScene;
use App\Play\Domain\Journal\InvalidJournalEntryContent;
use App\Play\Domain\Journal\InvalidJournalEntryId;
use App\Play\Domain\Journal\JournalEntryAlreadyExists;
use App\Play\Domain\Journal\NoteContent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(RecordNote::class)]
#[CoversClass(RecordNoteHandler::class)]
#[CoversClass(CampaignJournal::class)]
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

        self::assertEquals(NoteContent::of('The gate is shut.'), $this->onlyEntryOf('campaign-1')->content());
    }
}
