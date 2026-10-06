<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Journal;

use App\Play\Domain\Journal\ContentData;
use App\Play\Domain\Journal\InvalidJournalEntryContent;
use App\Play\Domain\Journal\JournalEntryContent;
use App\Play\Domain\Journal\JournalEntryContents;
use App\Play\Domain\Journal\LikelihoodContent;
use App\Play\Domain\Journal\NoteContent;
use App\Play\Domain\Journal\OracleTableContent;
use App\Play\Domain\Journal\RollContent;
use App\Randomness\Domain\DiceExpression;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * toArray() is the canonical stored shape of a content; fromArray() rebuilds it.
 */
#[CoversClass(JournalEntryContents::class)]
#[CoversClass(ContentData::class)]
#[CoversClass(NoteContent::class)]
#[CoversClass(RollContent::class)]
#[CoversClass(OracleTableContent::class)]
#[CoversClass(LikelihoodContent::class)]
#[CoversClass(InvalidJournalEntryContent::class)]
final class JournalEntryContentsTest extends TestCase
{
    #[Test]
    #[DataProvider('contents')]
    public function everyKindRoundTripsThroughItsArray(JournalEntryContent $content): void
    {
        $rebuilt = JournalEntryContents::fromArray($content->toArray());

        self::assertEquals($content, $rebuilt);
        self::assertSame($content->toArray(), $rebuilt->toArray());
    }

    /**
     * @return iterable<string, array{JournalEntryContent}>
     */
    public static function contents(): iterable
    {
        yield 'note' => [NoteContent::of('The gate is shut.')];
        yield 'roll' => [RollContent::fromRoll(DiceExpression::fromString('4d6kh3+1d4+1')->roll(new ScriptedRandomNumberGenerator(6, 2, 5, 3, 4)))];
        yield 'roll without dice' => [RollContent::fromRoll(DiceExpression::fromString('3')->roll(new ScriptedRandomNumberGenerator()))];
        yield 'oracle table' => [OracleTableContent::fromResult('weather', 'Weather', OracleTableContentTest::stormResult())];
        yield 'likelihood' => [LikelihoodContent::fromAnswer('fate', 'Fate', 'Is the gate open?', LikelihoodContentTest::likelyAnswer())];
        yield 'likelihood without question' => [LikelihoodContent::fromAnswer('fate', 'Fate', null, LikelihoodContentTest::likelyAnswer())];
    }

    /**
     * @param array<mixed> $data
     */
    #[Test]
    #[DataProvider('malformedData')]
    public function malformedDataIsRejected(array $data): void
    {
        $this->expectException(InvalidJournalEntryContent::class);

        JournalEntryContents::fromArray($data);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function malformedData(): iterable
    {
        $roll = RollContent::fromRoll(DiceExpression::fromString('2d6')->roll(new ScriptedRandomNumberGenerator(3, 4)))->toArray();
        $oracleTable = OracleTableContent::fromResult('weather', 'Weather', OracleTableContentTest::stormResult())->toArray();
        $likelihood = LikelihoodContent::fromAnswer('fate', 'Fate', null, LikelihoodContentTest::likelyAnswer())->toArray();

        yield 'no kind' => [['text' => 'Hello']];
        yield 'kind not a string' => [['kind' => 1, 'text' => 'Hello']];
        yield 'unknown kind' => [['kind' => 'drawing', 'text' => 'Hello']];
        yield 'note without text' => [['kind' => 'note']];
        yield 'note text not a string' => [['kind' => 'note', 'text' => 42]];
        yield 'blank note' => [['kind' => 'note', 'text' => '  ']];
        yield 'roll without total' => [array_diff_key($roll, ['total' => true])];
        yield 'roll total not an int' => [['total' => '7'] + $roll];
        yield 'roll groups not a list' => [['groups' => 'none'] + $roll];
        yield 'roll group not an array' => [['groups' => ['2d6']] + $roll];
        yield 'roll die kept not a bool' => [['groups' => [['notation' => '2d6', 'sides' => 6, 'dice' => [['value' => 3, 'kept' => 1]], 'subtotal' => 3]]] + $roll];
        yield 'oracle table without steps' => [['steps' => []] + $oracleTable];
        yield 'oracle table step without text' => [['steps' => [['tableKey' => 'weather', 'tableName' => 'Weather', 'dice' => '1d6', 'total' => 2, 'nestedTableKey' => null]]] + $oracleTable];
        yield 'oracle table blank key' => [['oracleKey' => ''] + $oracleTable];
        yield 'likelihood unknown answer' => [['answer' => 'maybe'] + $likelihood];
        yield 'likelihood chaos factor not an int' => [['chaosFactor' => 'high'] + $likelihood];
        yield 'likelihood question too long' => [['question' => str_repeat('a', 501)] + $likelihood];
        // A nullable field must still be present: null is stored explicitly, a missing key is malformed.
        yield 'likelihood without question key' => [array_diff_key($likelihood, ['question' => true])];
        yield 'likelihood without chaos factor key' => [array_diff_key($likelihood, ['chaosFactor' => true])];
        yield 'oracle table step without nested table key' => [['steps' => [['tableKey' => 'weather', 'tableName' => 'Weather', 'dice' => '1d6', 'total' => 2, 'text' => 'Clear']]] + $oracleTable];
    }
}
