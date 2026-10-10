<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Journal;

use App\Play\Domain\Journal\ChoiceContent;
use App\Play\Domain\Journal\InvalidJournalEntryContent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChoiceContent::class)]
#[CoversClass(InvalidJournalEntryContent::class)]
final class ChoiceContentTest extends TestCase
{
    #[Test]
    public function aChoiceKeepsItsTrimmedQuestionAndTheOptionChosen(): void
    {
        $choice = ChoiceContent::of(' Did you gain an edge? ', 'yes', ' Yes ');

        self::assertSame('choice', $choice->kind());
        self::assertSame(['Did you gain an edge?', 'yes', 'Yes'], [$choice->question(), $choice->optionKey(), $choice->label()]);
        self::assertSame(['kind' => 'choice', 'question' => 'Did you gain an edge?', 'optionKey' => 'yes', 'label' => 'Yes'], $choice->toArray());
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function invalidChoices(): iterable
    {
        yield 'blank question' => ['  ', 'yes', 'Yes', 'A choice question must not be blank.'];
        yield 'question too long' => [str_repeat('a', 501), 'yes', 'Yes', 'A question must be at most 500 characters, got 501.'];
        yield 'blank option key' => ['Did you?', ' ', 'Yes', 'A choice option key must not be blank.'];
        yield 'blank label' => ['Did you?', 'yes', '', 'A choice label must not be blank.'];
        yield 'label too long' => ['Did you?', 'yes', str_repeat('a', 101), 'A choice label must be at most 100 characters, got 101.'];
    }

    #[Test]
    #[DataProvider('invalidChoices')]
    public function anIncompleteChoiceIsRejected(string $question, string $optionKey, string $label, string $message): void
    {
        $this->expectException(InvalidJournalEntryContent::class);
        $this->expectExceptionMessageIsOrContains($message);

        ChoiceContent::of($question, $optionKey, $label);
    }
}
