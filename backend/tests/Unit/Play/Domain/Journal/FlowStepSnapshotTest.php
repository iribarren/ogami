<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Journal;

use App\Play\Domain\Journal\FlowStepSnapshot;
use App\Play\Domain\Journal\InvalidJournalEntryContent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FlowStepSnapshot::class)]
final class FlowStepSnapshotTest extends TestCase
{
    #[Test]
    public function aSnapshotKeepsTheStepAsGivenAndRoundTripsThroughItsArray(): void
    {
        $step = FlowStepSnapshot::of('gain-edge', 'Did you gain an edge?', 'Heat is 3: {tracker:heat} rendered.');
        $withoutPrompt = FlowStepSnapshot::of('plan', 'The plan', null);

        self::assertSame(['key' => 'gain-edge', 'title' => 'Did you gain an edge?', 'prompt' => 'Heat is 3: {tracker:heat} rendered.'], $step->toArray());
        self::assertSame(['plan', 'The plan', null], [$withoutPrompt->key, $withoutPrompt->title, $withoutPrompt->prompt]);
        self::assertEquals($step, FlowStepSnapshot::fromArray($step->toArray()));
        self::assertEquals($withoutPrompt, FlowStepSnapshot::fromArray($withoutPrompt->toArray()));
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function malformedData(): iterable
    {
        yield 'no key' => [['title' => 'The plan', 'prompt' => null], 'Malformed journal entry content: "key" must be a string.'];
        yield 'blank key' => [['key' => ' ', 'title' => 'The plan', 'prompt' => null], 'A Flow step key must not be blank.'];
        yield 'title not a string' => [['key' => 'plan', 'title' => 7, 'prompt' => null], 'Malformed journal entry content: "title" must be a string.'];
        yield 'blank title' => [['key' => 'plan', 'title' => '', 'prompt' => null], 'A Flow step title must not be blank.'];
        yield 'no prompt key' => [['key' => 'plan', 'title' => 'The plan'], 'Malformed journal entry content: "prompt" must be a string or null.'];
        yield 'prompt not a string' => [['key' => 'plan', 'title' => 'The plan', 'prompt' => false], 'Malformed journal entry content: "prompt" must be a string.'];
    }

    /**
     * @param array<mixed> $data
     */
    #[Test]
    #[DataProvider('malformedData')]
    public function malformedDataIsRejected(array $data, string $message): void
    {
        $this->expectException(InvalidJournalEntryContent::class);
        $this->expectExceptionMessageIsOrContains($message);

        FlowStepSnapshot::fromArray($data);
    }
}
