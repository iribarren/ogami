<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Journal;

use App\Play\Domain\Journal\InvalidJournalEntryContent;
use App\Play\Domain\Journal\InvalidNoteAttachment;
use App\Play\Domain\Journal\NoteContent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NoteContent::class)]
#[CoversClass(InvalidJournalEntryContent::class)]
#[CoversClass(InvalidNoteAttachment::class)]
final class NoteContentTest extends TestCase
{
    #[Test]
    public function aNoteKeepsItsTrimmedText(): void
    {
        $note = NoteContent::of("  The gate is shut.\n");

        self::assertSame('note', $note->kind());
        self::assertSame('The gate is shut.', $note->text());
        self::assertSame(['kind' => 'note', 'text' => 'The gate is shut.', 'attachments' => []], $note->toArray());
    }

    #[Test]
    public function aNoteOfTenThousandCharactersIsAccepted(): void
    {
        $text = str_repeat('é', 10_000);

        self::assertSame($text, NoteContent::of($text)->text());
    }

    #[Test]
    public function aNoteAboveTenThousandCharactersIsRejected(): void
    {
        $this->expectException(InvalidJournalEntryContent::class);
        $this->expectExceptionMessageIsOrContains('A note must be at most 10000 characters, got 10001.');

        NoteContent::of(str_repeat('a', 10_001));
    }

    #[Test]
    #[DataProvider('blankTexts')]
    public function aBlankNoteIsRejected(string $text): void
    {
        $this->expectException(InvalidJournalEntryContent::class);
        $this->expectExceptionMessageIsOrContains('A note must not be blank.');

        NoteContent::of($text);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankTexts(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'whitespace' => [" \t\n "];
    }

    #[Test]
    public function aNoteWithoutAttachmentsKeepsNone(): void
    {
        $note = NoteContent::of('The gate is shut.');

        self::assertSame([], $note->attachments());
        self::assertSame(['kind' => 'note', 'text' => 'The gate is shut.', 'attachments' => []], $note->toArray());
    }

    #[Test]
    public function aNoteKeepsItsAttachmentsInTheOrderGiven(): void
    {
        $note = NoteContent::of('The gate is shut.', ['entry-3', 'entry-1', 'entry-2']);

        self::assertSame(['entry-3', 'entry-1', 'entry-2'], $note->attachments());
        self::assertSame(['kind' => 'note', 'text' => 'The gate is shut.', 'attachments' => ['entry-3', 'entry-1', 'entry-2']], $note->toArray());
    }

    #[Test]
    public function aNoteWithTwentyAttachmentsIsAccepted(): void
    {
        $ids = array_map(static fn (int $n): string => 'entry-'.$n, range(1, 20));

        self::assertSame($ids, NoteContent::of('The gate is shut.', $ids)->attachments());
    }

    #[Test]
    public function moreThanTwentyAttachmentsAreRejected(): void
    {
        $ids = array_map(static fn (int $n): string => 'entry-'.$n, range(1, 21));

        $this->expectException(InvalidNoteAttachment::class);
        $this->expectExceptionMessageIsOrContains('A note attaches at most 20 results, 21 given.');

        NoteContent::of('The gate is shut.', $ids);
    }

    #[Test]
    public function aDuplicateAttachmentIsRejected(): void
    {
        $this->expectException(InvalidNoteAttachment::class);
        $this->expectExceptionMessageIsOrContains('Journal entry "entry-1" is attached twice.');

        NoteContent::of('The gate is shut.', ['entry-1', 'entry-2', 'entry-1']);
    }

    #[Test]
    public function aBlankAttachmentIdIsRejected(): void
    {
        $this->expectException(InvalidNoteAttachment::class);
        $this->expectExceptionMessageIsOrContains('An attached journal entry id must not be blank.');

        NoteContent::of('The gate is shut.', ['entry-1', ' ']);
    }

    #[Test]
    public function aBlankNoteWithAttachmentsIsStillRejected(): void
    {
        $this->expectException(InvalidJournalEntryContent::class);

        NoteContent::of('  ', ['entry-1']);
    }

    #[Test]
    public function aLegacyNoteWithoutTheAttachmentsKeyReadsNone(): void
    {
        $note = NoteContent::fromArray(['kind' => 'note', 'text' => 'The gate is shut.']);

        self::assertSame([], $note->attachments());
        self::assertEquals(NoteContent::of('The gate is shut.'), $note);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[Test]
    #[DataProvider('malformedStoredNotes')]
    public function aMalformedStoredNoteFailsAsMalformedContent(array $data): void
    {
        $this->expectException(InvalidJournalEntryContent::class);
        $this->expectExceptionMessageIsOrContains('"attachments"');

        NoteContent::fromArray($data);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedStoredNotes(): iterable
    {
        yield 'a string' => [['kind' => 'note', 'text' => 'A note.', 'attachments' => 'entry-1']];
        yield 'a number in the list' => [['kind' => 'note', 'text' => 'A note.', 'attachments' => [3]]];
        yield 'a blank id' => [['kind' => 'note', 'text' => 'A note.', 'attachments' => ['entry-1', ' ']]];
        yield 'a duplicate' => [['kind' => 'note', 'text' => 'A note.', 'attachments' => ['entry-1', 'entry-1']]];
        yield 'twenty-one ids' => [['kind' => 'note', 'text' => 'A note.', 'attachments' => array_map(static fn (int $n): string => 'entry-'.$n, range(1, 21))]];
    }

    #[Test]
    public function aNoteRoundTripsThroughItsArray(): void
    {
        $note = NoteContent::of('The gate is shut.', ['entry-2', 'entry-1']);

        self::assertEquals($note, NoteContent::fromArray($note->toArray()));
    }

    #[Test]
    #[DataProvider('malformedAttachments')]
    public function storedAttachmentsThatAreNotAListOfStringsAreMalformed(mixed $attachments): void
    {
        $this->expectException(InvalidJournalEntryContent::class);
        $this->expectExceptionMessageIsOrContains('Malformed journal entry content: "attachments" must be a list of strings.');

        NoteContent::fromArray(['kind' => 'note', 'text' => 'The gate is shut.', 'attachments' => $attachments]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedAttachments(): iterable
    {
        yield 'null' => [null];
        yield 'a string' => ['entry-1'];
        yield 'an object' => [['a' => 'entry-1']];
        yield 'a number in the list' => [['entry-1', 2]];
    }
}
