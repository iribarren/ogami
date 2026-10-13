<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Journal;

use App\Formula\Domain\Formula;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\GameSystem\Check\Check;
use App\Play\Domain\GameSystem\Check\CheckBand;
use App\Play\Domain\GameSystem\Check\CheckDifficulty;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Play\Domain\Journal\CheckContent;
use App\Play\Domain\Journal\InvalidJournalEntryContent;
use App\Play\Domain\Journal\JournalEntryContents;
use App\Randomness\Domain\DiceExpression;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CheckContent::class)]
#[CoversClass(InvalidJournalEntryContent::class)]
final class CheckContentTest extends TestCase
{
    /**
     * Vi (hands 3) rolls the check "edge": 2d6 + {hands} against a difficulty of 8, with a situational
     * modifier of -1; the dice show 5 and 4.
     */
    public static function edgeCheck(): CheckContent
    {
        $check = new Check(
            'edge',
            'Edge',
            null,
            DiceExpression::fromString('2d6'),
            Formula::fromString('{hands}'),
            new CheckDifficulty('Difficulty', 8),
            [new CheckBand('miss', 'Miss', Formula::fromString('{check:difficulty} - 1')), new CheckBand('hit', 'Hit')],
        );
        $sheet = SheetValues::of(new SheetTemplate([new NumberField('hands', 'Hands')]), ['hands' => 3], []);
        $result = $check->perform(new ScriptedRandomNumberGenerator(5, 4), $sheet, null, -1);

        return CheckContent::fromResult('char-1', 'Vi', $check, $result);
    }

    #[Test]
    public function aCheckKeepsTheCharacterTheCheckTheDiceTheModifiersTheTotalAndTheBand(): void
    {
        $content = self::edgeCheck();

        self::assertSame('check', $content->kind());
        self::assertSame([
            'kind' => 'check',
            'characterId' => 'char-1',
            'characterName' => 'Vi',
            'checkKey' => 'edge',
            'checkLabel' => 'Edge',
            'expression' => '2d6',
            'groups' => [['notation' => '2d6', 'sides' => 6, 'dice' => [['value' => 5, 'kept' => true], ['value' => 4, 'kept' => true]], 'subtotal' => 9]],
            'diceTotal' => 9,
            'modifier' => 3,
            'situational' => -1,
            'difficulty' => 8,
            'total' => 11,
            'bandKey' => 'hit',
            'bandLabel' => 'Hit',
        ], $content->toArray());
        self::assertSame(['char-1', 'Vi', 'edge', 'Edge', '2d6', 9, 3, -1, 8, 11, 'hit', 'Hit'], [
            $content->characterId(), $content->characterName(), $content->checkKey(), $content->checkLabel(), $content->expression(),
            $content->diceTotal(), $content->modifier(), $content->situational(), $content->difficulty(), $content->total(), $content->bandKey(), $content->bandLabel(),
        ]);
        self::assertSame($content->toArray()['groups'], $content->groups());
    }

    #[Test]
    public function aCheckWithoutADifficultyStoresNull(): void
    {
        $check = new Check('luck', 'Luck', null, DiceExpression::fromString('d%'), null, null, [new CheckBand('only', 'Only')]);
        $result = $check->perform(new ScriptedRandomNumberGenerator(42), SheetValues::of(new SheetTemplate([]), [], []), null, 0);

        $content = CheckContent::fromResult('char-1', 'Vi', $check, $result);

        self::assertNull($content->difficulty());
        self::assertNull($content->toArray()['difficulty']);
        self::assertEquals($content, JournalEntryContents::fromArray($content->toArray()));
    }

    #[Test]
    public function aCheckRoundTripsThroughItsArray(): void
    {
        $content = self::edgeCheck();

        self::assertEquals($content, JournalEntryContents::fromArray($content->toArray()));
    }

    /**
     * @param array<mixed> $data
     */
    #[Test]
    #[DataProvider('malformedData')]
    public function malformedStoredDataIsRejected(array $data, string $message): void
    {
        $this->expectException(InvalidJournalEntryContent::class);
        $this->expectExceptionMessageIsOrContains($message);

        JournalEntryContents::fromArray($data);
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function malformedData(): iterable
    {
        $valid = self::edgeCheck()->toArray();

        foreach (['characterId', 'characterName', 'checkKey', 'checkLabel', 'expression', 'bandKey', 'bandLabel'] as $field) {
            yield \sprintf('%s missing', $field) => [array_diff_key($valid, [$field => true]), \sprintf('"%s" must be a string', $field)];
            yield \sprintf('%s not a string', $field) => [[$field => 7] + $valid, \sprintf('"%s" must be a string', $field)];
        }

        foreach (['diceTotal', 'modifier', 'situational', 'total'] as $field) {
            yield \sprintf('%s missing', $field) => [array_diff_key($valid, [$field => true]), \sprintf('"%s" must be an integer', $field)];
            yield \sprintf('%s not an integer', $field) => [[$field => '7'] + $valid, \sprintf('"%s" must be an integer', $field)];
        }

        yield 'difficulty missing' => [array_diff_key($valid, ['difficulty' => true]), '"difficulty" must be an integer or null'];
        yield 'difficulty not an integer' => [['difficulty' => 8.5] + $valid, '"difficulty" must be an integer'];
        yield 'groups missing' => [array_diff_key($valid, ['groups' => true]), '"groups" must be a list'];
        yield 'groups not a list' => [['groups' => 'none'] + $valid, '"groups" must be a list'];
        yield 'die kept not a bool' => [['groups' => [['notation' => '2d6', 'sides' => 6, 'dice' => [['value' => 3, 'kept' => 1]], 'subtotal' => 3]]] + $valid, '"kept" must be a boolean'];
        yield 'blank character id' => [['characterId' => ' '] + $valid, 'A Character id must not be blank.'];
        yield 'blank check key' => [['checkKey' => ''] + $valid, 'A check key must not be blank.'];
        yield 'blank band key' => [['bandKey' => ''] + $valid, 'A band key must not be blank.'];
        yield 'total that is not the dice plus the modifiers' => [['total' => 12] + $valid, '"total" must be the dice total plus the modifier and the situational modifier'];
        yield 'total that overflows' => [['diceTotal' => \PHP_INT_MAX, 'modifier' => 1, 'total' => \PHP_INT_MAX] + $valid, '"total" must be the dice total plus the modifier and the situational modifier'];
    }
}
