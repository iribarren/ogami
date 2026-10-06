<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Journal;

use App\Play\Domain\Journal\InvalidJournalEntryContent;
use App\Play\Domain\Journal\NoteContent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NoteContent::class)]
#[CoversClass(InvalidJournalEntryContent::class)]
final class NoteContentTest extends TestCase
{
    #[Test]
    public function aNoteKeepsItsTrimmedText(): void
    {
        $note = NoteContent::of("  The gate is shut.\n");

        self::assertSame('note', $note->kind());
        self::assertSame('The gate is shut.', $note->text());
        self::assertSame(['kind' => 'note', 'text' => 'The gate is shut.'], $note->toArray());
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
}
