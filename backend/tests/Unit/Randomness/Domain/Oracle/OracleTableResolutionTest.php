<?php

declare(strict_types=1);

namespace App\Tests\Unit\Randomness\Domain\Oracle;

use App\Randomness\Domain\Oracle\InvalidOracleTable;
use App\Randomness\Domain\Oracle\OracleTable;
use App\Randomness\Domain\Oracle\OracleTableEntry;
use App\Randomness\Domain\Oracle\OracleTableResult;
use App\Randomness\Domain\Oracle\OracleTableSet;
use App\Randomness\Domain\Oracle\OracleTableStep;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OracleTableSet::class)]
#[CoversClass(OracleTable::class)]
#[CoversClass(OracleTableEntry::class)]
#[CoversClass(OracleTableResult::class)]
#[CoversClass(OracleTableStep::class)]
#[CoversClass(InvalidOracleTable::class)]
final class OracleTableResolutionTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function weather(): array
    {
        return [
            'key' => 'weather',
            'name' => 'Weather',
            'dice' => '1d6',
            'entries' => [
                ['min' => 1, 'max' => 3, 'text' => 'Clear'],
                ['min' => 4, 'max' => 5, 'text' => 'Rain'],
                ['min' => 6, 'max' => 6, 'text' => 'Storm', 'table' => 'storm-kind'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stormKind(): array
    {
        return [
            'key' => 'storm-kind',
            'name' => 'Storm kind',
            'entries' => [
                ['weight' => 3, 'text' => 'Thunderstorm'],
                ['text' => 'Hail'],
                ['weight' => 2, 'text' => 'Blizzard'],
            ],
        ];
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function rangedRolls(): iterable
    {
        yield 'lowest roll of the first range' => [1, 'Clear'];
        yield 'highest roll of the first range' => [3, 'Clear'];
        yield 'lowest roll of the second range' => [4, 'Rain'];
        yield 'highest roll of the second range' => [5, 'Rain'];
    }

    #[Test]
    #[DataProvider('rangedRolls')]
    public function itResolvesARangedTable(int $roll, string $text): void
    {
        $result = OracleTableSet::fromArray([$this->weather(), $this->stormKind()])
            ->resolve('weather', new ScriptedRandomNumberGenerator($roll));

        self::assertCount(1, $result->steps());
        $step = $result->steps()[0];
        self::assertSame('weather', $step->tableKey());
        self::assertSame('Weather', $step->tableName());
        self::assertSame('1d6', $step->dice());
        self::assertSame($roll, $step->total());
        self::assertSame($text, $step->text());
        self::assertNull($step->nestedTableKey());
    }

    #[Test]
    public function itRollsTheTableDiceExpression(): void
    {
        $result = OracleTableSet::fromArray([[
            'key' => 'encounter',
            'name' => 'Encounter',
            'dice' => '2D6 + 1',
            'entries' => [
                ['min' => 3, 'max' => 7, 'text' => 'Nothing'],
                ['min' => 8, 'max' => 13, 'text' => 'Wolves'],
            ],
        ]])->resolve('encounter', new ScriptedRandomNumberGenerator(4, 5));

        self::assertSame('2d6+1', $result->steps()[0]->dice());
        self::assertSame(10, $result->steps()[0]->total());
        self::assertSame('Wolves', $result->steps()[0]->text());
    }

    /**
     * Weights 3, 1 and 2: Thunderstorm on 1–3, Hail on 4, Blizzard on 5–6.
     *
     * @return iterable<string, array{int, string}>
     */
    public static function weightedRolls(): iterable
    {
        yield 'first weight, lowest' => [1, 'Thunderstorm'];
        yield 'first weight, highest' => [3, 'Thunderstorm'];
        yield 'default weight of one' => [4, 'Hail'];
        yield 'last weight, lowest' => [5, 'Blizzard'];
        yield 'last weight, highest' => [6, 'Blizzard'];
    }

    #[Test]
    #[DataProvider('weightedRolls')]
    public function itResolvesAWeightedTableByWalkingTheCumulativeWeights(int $roll, string $text): void
    {
        $result = OracleTableSet::fromArray([$this->stormKind()])
            ->resolve('storm-kind', new ScriptedRandomNumberGenerator($roll));

        self::assertCount(1, $result->steps());
        self::assertSame('1d6', $result->steps()[0]->dice());
        self::assertSame($roll, $result->steps()[0]->total());
        self::assertSame($text, $result->steps()[0]->text());
    }

    #[Test]
    public function itRollsAWeightedTableAboveTheDiceSidesLimit(): void
    {
        $result = OracleTableSet::fromArray([[
            'key' => 'rare',
            'name' => 'Rare finds',
            'entries' => [
                ['weight' => 999_999, 'text' => 'Nothing'],
                ['text' => 'A dragon egg'],
            ],
        ]])->resolve('rare', new ScriptedRandomNumberGenerator(1_000_000));

        self::assertSame('1d1000000', $result->steps()[0]->dice());
        self::assertSame('A dragon egg', $result->steps()[0]->text());
    }

    #[Test]
    public function itResolvesANestedTableAndReturnsEveryStepRootFirst(): void
    {
        $result = OracleTableSet::fromArray([$this->weather(), $this->stormKind()])
            ->resolve('weather', new ScriptedRandomNumberGenerator(6, 5));

        self::assertCount(2, $result->steps());
        [$root, $nested] = $result->steps();

        self::assertSame('weather', $root->tableKey());
        self::assertSame(6, $root->total());
        self::assertSame('Storm', $root->text());
        self::assertSame('storm-kind', $root->nestedTableKey());

        self::assertSame('storm-kind', $nested->tableKey());
        self::assertSame('Storm kind', $nested->tableName());
        self::assertSame('1d6', $nested->dice());
        self::assertSame(5, $nested->total());
        self::assertSame('Blizzard', $nested->text());
        self::assertNull($nested->nestedTableKey());
    }

    #[Test]
    public function itResolvesSeveralLevelsOfNestingWithAnEmptyTextOnNestingEntries(): void
    {
        $result = OracleTableSet::fromArray([
            ['key' => 'a', 'name' => 'A', 'entries' => [['table' => 'b']]],
            ['key' => 'b', 'name' => 'B', 'entries' => [['text' => '', 'table' => 'c']]],
            ['key' => 'c', 'name' => 'C', 'dice' => '1d4', 'entries' => [['min' => 1, 'max' => 4, 'text' => 'Done']]],
        ])->resolve('a', new ScriptedRandomNumberGenerator(1, 1, 2));

        self::assertSame(['a', 'b', 'c'], array_map(static fn (OracleTableStep $step): string => $step->tableKey(), $result->steps()));
        self::assertSame(['', '', 'Done'], array_map(static fn (OracleTableStep $step): string => $step->text(), $result->steps()));
    }

    #[Test]
    public function itResolvesATableThatIsAlsoNestedElsewhere(): void
    {
        $result = OracleTableSet::fromArray([$this->weather(), $this->stormKind()])
            ->resolve('storm-kind', new ScriptedRandomNumberGenerator(4));

        self::assertSame('Hail', $result->steps()[0]->text());
    }

    #[Test]
    public function itFailsWhenTheRollMatchesNoEntry(): void
    {
        $set = OracleTableSet::fromArray([[
            'key' => 'gaps',
            'name' => 'Gaps',
            'dice' => '1d6',
            'entries' => [
                ['min' => 1, 'max' => 2, 'text' => 'Low'],
                ['min' => 5, 'max' => 6, 'text' => 'High'],
            ],
        ]]);

        $this->expectException(InvalidOracleTable::class);
        $this->expectExceptionMessageIsOrContains('Rolled 3 on table "gaps", but no entry covers it.');

        $set->resolve('gaps', new ScriptedRandomNumberGenerator(3));
    }

    #[Test]
    public function itFailsWhenANestedRollMatchesNoEntry(): void
    {
        $set = OracleTableSet::fromArray([
            ['key' => 'root', 'name' => 'Root', 'entries' => [['text' => 'Go', 'table' => 'leaf']]],
            ['key' => 'leaf', 'name' => 'Leaf', 'dice' => '1d6', 'entries' => [['min' => 1, 'max' => 5, 'text' => 'Ok']]],
        ]);

        $this->expectException(InvalidOracleTable::class);
        $this->expectExceptionMessageIsOrContains('Rolled 6 on table "leaf", but no entry covers it.');

        $set->resolve('root', new ScriptedRandomNumberGenerator(1, 6));
    }

    #[Test]
    public function itFailsWhenTheDiceCannotBeEvaluated(): void
    {
        $set = OracleTableSet::fromArray([[
            'key' => 'broken',
            'name' => 'Broken',
            'dice' => '1d6/0',
            'entries' => [['min' => 0, 'max' => 6, 'text' => 'Never']],
        ]]);

        $this->expectException(InvalidOracleTable::class);
        $this->expectExceptionMessageIsOrContains('Table "broken" cannot roll "1d6/0": "1d6/0" divides by zero.');

        $set->resolve('broken', new ScriptedRandomNumberGenerator(3));
    }

    #[Test]
    public function itFailsToResolveAnUnknownTable(): void
    {
        $set = OracleTableSet::fromArray([$this->stormKind()]);

        $this->expectException(InvalidOracleTable::class);
        $this->expectExceptionMessageIsOrContains('There is no oracle table "weather" in the table set.');

        $set->resolve('weather', new ScriptedRandomNumberGenerator());
    }

    #[Test]
    public function itExposesItsTablesByKey(): void
    {
        $set = OracleTableSet::fromArray([$this->weather(), $this->stormKind()]);

        self::assertSame(['weather', 'storm-kind'], $set->keys());
        self::assertTrue($set->table('weather')->isRanged());
        self::assertSame('1d6', $set->table('weather')->dice()?->notation());
        self::assertFalse($set->table('storm-kind')->isRanged());
        self::assertNull($set->table('storm-kind')->dice());
        self::assertSame(6, $set->table('storm-kind')->totalWeight());
        self::assertSame([3, 1, 2], array_map(
            static fn (OracleTableEntry $entry): ?int => $entry->weight(),
            $set->table('storm-kind')->entries(),
        ));
    }
}
