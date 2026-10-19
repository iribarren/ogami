<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Journal;

use App\Play\Domain\Journal\FlowOutcomeContent;
use App\Play\Domain\Journal\InvalidJournalEntryContent;
use App\Play\Domain\Journal\JournalEntryContents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FlowOutcomeContent::class)]
#[CoversClass(InvalidJournalEntryContent::class)]
final class FlowOutcomeContentTest extends TestCase
{
    #[Test]
    public function itKeepsEveryKindOfNoteInOrderAndRoundTripsThroughItsArray(): void
    {
        $notes = [
            ['kind' => 'band', 'label' => 'Strong hit'],
            ['kind' => 'tracker', 'key' => 'threat', 'name' => 'Threat', 'from' => 3, 'to' => 4],
            ['kind' => 'switch', 'from' => 'Chase', 'to' => 'Combat'],
            ['kind' => 'nextScene', 'sceneType' => 'Briefing'],
            ['kind' => 'phaseEnd', 'phase' => 'Heist'],
        ];

        $content = FlowOutcomeContent::of($notes);

        self::assertSame('flowOutcome', $content->kind());
        self::assertSame($notes, $content->notes());
        self::assertSame(['kind' => 'flowOutcome', 'notes' => $notes], $content->toArray());
        self::assertEquals($content, JournalEntryContents::fromArray($content->toArray()));
    }

    #[Test]
    public function extraFieldsOfANoteAreNotKept(): void
    {
        $content = FlowOutcomeContent::of([['kind' => 'band', 'label' => 'Miss', 'colour' => 'red']]);

        self::assertSame([['kind' => 'band', 'label' => 'Miss']], $content->notes());
    }

    #[Test]
    public function aStoredEntryWithoutNotesIsRejected(): void
    {
        $this->expectException(InvalidJournalEntryContent::class);

        JournalEntryContents::fromArray(['kind' => 'flowOutcome', 'notes' => []]);
    }

    /**
     * @param array<string, mixed> $note
     */
    #[Test]
    #[DataProvider('malformedNotes')]
    public function aMalformedOrUnknownNoteFailsClosed(array $note): void
    {
        $this->expectException(InvalidJournalEntryContent::class);

        JournalEntryContents::fromArray(['kind' => 'flowOutcome', 'notes' => [$note]]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedNotes(): iterable
    {
        yield 'unknown kind' => [['kind' => 'weather', 'label' => 'Rain']];
        yield 'no kind' => [['label' => 'Rain']];
        yield 'band without label' => [['kind' => 'band']];
        yield 'band with blank label' => [['kind' => 'band', 'label' => '  ']];
        yield 'tracker without a value' => [['kind' => 'tracker', 'key' => 'threat', 'name' => 'Threat', 'from' => 3]];
        yield 'tracker with a text value' => [['kind' => 'tracker', 'key' => 'threat', 'name' => 'Threat', 'from' => '3', 'to' => 4]];
        yield 'tracker with a blank name' => [['kind' => 'tracker', 'key' => 'threat', 'name' => '', 'from' => 3, 'to' => 4]];
        yield 'switch without target' => [['kind' => 'switch', 'from' => 'Chase']];
        yield 'nextScene with blank Scene Type' => [['kind' => 'nextScene', 'sceneType' => '']];
        yield 'phaseEnd without phase' => [['kind' => 'phaseEnd']];
    }

    #[Test]
    public function notesThatAreNotAListFailClosed(): void
    {
        $this->expectException(InvalidJournalEntryContent::class);

        JournalEntryContents::fromArray(['kind' => 'flowOutcome', 'notes' => ['band' => 'Miss']]);
    }
}
