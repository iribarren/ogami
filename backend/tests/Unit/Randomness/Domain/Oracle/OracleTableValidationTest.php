<?php

declare(strict_types=1);

namespace App\Tests\Unit\Randomness\Domain\Oracle;

use App\Randomness\Domain\Oracle\InvalidOracleTable;
use App\Randomness\Domain\Oracle\OracleTable;
use App\Randomness\Domain\Oracle\OracleTableEntry;
use App\Randomness\Domain\Oracle\OracleTableSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OracleTableSet::class)]
#[CoversClass(OracleTable::class)]
#[CoversClass(OracleTableEntry::class)]
#[CoversClass(InvalidOracleTable::class)]
final class OracleTableValidationTest extends TestCase
{
    /**
     * @param list<mixed> $entries
     *
     * @return array<string, mixed>
     */
    private static function ranged(string $key = 'weather', array $entries = [['min' => 1, 'max' => 6, 'text' => 'Clear']], string $dice = '1d6'): array
    {
        return ['key' => $key, 'name' => 'Weather', 'dice' => $dice, 'entries' => $entries];
    }

    /**
     * @param list<mixed> $entries
     *
     * @return array<string, mixed>
     */
    private static function weighted(string $key = 'mood', array $entries = [['text' => 'Calm']]): array
    {
        return ['key' => $key, 'name' => 'Mood', 'entries' => $entries];
    }

    /**
     * A chain of $length tables, each nesting the next one.
     *
     * @return list<array<string, mixed>>
     */
    private static function chain(int $length): array
    {
        $tables = [];
        for ($i = 1; $i <= $length; ++$i) {
            $entry = $i < $length ? ['text' => 'Deeper', 'table' => 't'.($i + 1)] : ['text' => 'Bottom'];
            $tables[] = self::weighted('t'.$i, [$entry]);
        }

        return $tables;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function entries(int $count): array
    {
        return array_map(static fn (int $i): array => ['text' => 'Entry '.$i], range(1, $count));
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function definitionsAtTheLimits(): iterable
    {
        yield 'one table' => [[self::weighted()]];
        yield '50 tables' => [array_map(static fn (int $i): array => self::weighted('t'.$i), range(1, 50))];
        yield 'one entry' => [[self::weighted('one', self::entries(1))]];
        yield '1000 entries' => [[self::weighted('many', self::entries(1000))]];
        yield '64-character key' => [[self::weighted(str_repeat('a', 64))]];
        yield 'key with digits and dashes' => [[self::weighted('npc-reaction-2')]];
        yield '500-character name' => [[['key' => 'long', 'name' => str_repeat('é', 500), 'entries' => [['text' => 'Ok']]]]];
        yield '500-character text' => [[self::weighted('long', [['text' => str_repeat('é', 500)]])]];
        yield 'total weight of 1,000,000' => [[self::weighted('heavy', [['weight' => 500_000, 'text' => 'A'], ['weight' => 500_000, 'text' => 'B']])]];
        yield 'adjacent ranges' => [[self::ranged('adjacent', [['min' => 1, 'max' => 3, 'text' => 'A'], ['min' => 4, 'max' => 6, 'text' => 'B']])]];
        yield 'single-value range' => [[self::ranged('single', [['min' => 4, 'max' => 4, 'text' => 'A']])]];
        yield 'unsorted ranges' => [[self::ranged('unsorted', [['min' => 4, 'max' => 6, 'text' => 'B'], ['min' => 1, 'max' => 3, 'text' => 'A']])]];
        yield 'ranges with gaps' => [[self::ranged('gaps', [['min' => 1, 'max' => 2, 'text' => 'A'], ['min' => 5, 'max' => 6, 'text' => 'B']])]];
        yield 'null dice is weighted' => [[['key' => 'nil', 'name' => 'Nil', 'dice' => null, 'entries' => [['text' => 'Ok']]]]];
        yield 'nesting 10 levels deep' => [self::chain(11)];
        yield 'shared nested table' => [[
            self::weighted('a', [['text' => 'B', 'table' => 'c'], ['text' => 'C', 'table' => 'c']]),
            self::weighted('b', [['table' => 'c']]),
            self::weighted('c'),
        ]];
    }

    /**
     * @param array<mixed> $tables
     */
    #[Test]
    #[DataProvider('definitionsAtTheLimits')]
    public function itAcceptsDefinitionsAtTheLimits(array $tables): void
    {
        self::assertNotEmpty(OracleTableSet::fromArray($tables)->keys());
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function invalidDefinitions(): iterable
    {
        // The table set
        yield 'no tables' => [[], 'A table set has between 1 and 50 oracle tables, 0 given.'];
        yield '51 tables' => [array_map(static fn (int $i): array => self::weighted('t'.$i), range(1, 51)), 'A table set has between 1 and 50 oracle tables, 51 given.'];
        yield 'tables not a list' => [['weather' => self::weighted()], 'The oracle tables are a list.'];
        yield 'duplicate keys' => [[self::weighted('mood'), self::weighted('mood')], 'Two oracle tables have the key "mood"; keys are unique within a table set.'];

        // Table shape
        yield 'table not an object' => [['weather'], 'Oracle table 1 is not an object.'];
        yield 'missing key' => [[['name' => 'Mood', 'entries' => [['text' => 'Calm']]]], 'Oracle table 1 has no "key" string.'];
        yield 'key not a string' => [[['key' => 7, 'name' => 'Mood', 'entries' => [['text' => 'Calm']]]], 'Oracle table 1 has no "key" string.'];
        yield 'missing name' => [[['key' => 'mood', 'entries' => [['text' => 'Calm']]]], 'Oracle table "mood" has no "name" string.'];
        yield 'dice not a string' => [[['key' => 'mood', 'name' => 'Mood', 'dice' => 6, 'entries' => [['text' => 'Calm']]]], 'The "dice" of oracle table "mood" is a string.'];
        yield 'missing entries' => [[['key' => 'mood', 'name' => 'Mood']], 'Oracle table "mood" has no "entries" list.'];
        yield 'entries not a list' => [[['key' => 'mood', 'name' => 'Mood', 'entries' => ['a' => ['text' => 'Calm']]]], 'Oracle table "mood" has no "entries" list.'];

        // Keys, names and texts
        yield 'empty key' => [[self::weighted('')], 'An oracle table key has 1 to 64 characters among a-z, 0-9 and "-", "" given.'];
        yield '65-character key' => [[self::weighted(str_repeat('a', 65))], 'An oracle table key has 1 to 64 characters'];
        yield 'upper-case key' => [[self::weighted('Mood')], 'An oracle table key has 1 to 64 characters among a-z, 0-9 and "-", "Mood" given.'];
        yield 'key with spaces' => [[self::weighted('npc mood')], '"npc mood" given.'];
        yield 'blank name' => [[['key' => 'mood', 'name' => '  ', 'entries' => [['text' => 'Calm']]]], 'Oracle table "mood" has a name of 1 to 500 characters, 0 given.'];
        yield '501-character name' => [[['key' => 'mood', 'name' => str_repeat('a', 501), 'entries' => [['text' => 'Calm']]]], 'Oracle table "mood" has a name of 1 to 500 characters, 501 given.'];

        // Entries
        yield 'no entries' => [[self::weighted('mood', [])], 'Oracle table "mood" has between 1 and 1000 entries, 0 given.'];
        yield '1001 entries' => [[self::weighted('mood', self::entries(1001))], 'Oracle table "mood" has between 1 and 1000 entries, 1001 given.'];
        yield 'entry not an object' => [[self::weighted('mood', ['Calm'])], 'Entry 1 of oracle table "mood" is not an object.'];
        yield 'text not a string' => [[self::weighted('mood', [['text' => 3]])], 'The "text" of entry 1 of oracle table "mood" is a string.'];
        yield 'min not an integer' => [[self::ranged('weather', [['min' => '1', 'max' => 6, 'text' => 'A']])], 'The "min" of entry 1 of oracle table "weather" is an integer.'];
        yield 'max not an integer' => [[self::ranged('weather', [['min' => 1, 'max' => 6.0, 'text' => 'A']])], 'The "max" of entry 1 of oracle table "weather" is an integer.'];
        yield 'weight not an integer' => [[self::weighted('mood', [['weight' => true, 'text' => 'A']])], 'The "weight" of entry 1 of oracle table "mood" is an integer.'];
        yield 'nested key not a string' => [[self::weighted('mood', [['text' => 'A', 'table' => 1]])], 'The "table" of entry 1 of oracle table "mood" is a string.'];
        yield 'empty text without nested table' => [[self::weighted('mood', [['text' => 'Calm'], ['text' => ' ']])], 'Entry 2 of oracle table "mood" has a text of 1 to 500 characters, 0 given.'];
        yield 'missing text without nested table' => [[self::weighted('mood', [['weight' => 2]])], 'Entry 1 of oracle table "mood" has a text of 1 to 500 characters, 0 given.'];
        yield '501-character text' => [[self::weighted('mood', [['text' => str_repeat('é', 501)]])], 'Entry 1 of oracle table "mood" has a text of 1 to 500 characters, 501 given.'];
        yield '501-character text with nested table' => [[self::weighted('mood', [['text' => str_repeat('a', 501), 'table' => 'mood']])], 'has a text of at most 500 characters, 501 given.'];

        // Ranged tables
        yield 'invalid dice' => [[self::ranged('weather', dice: '1d1')], 'Oracle table "weather" has invalid dice "1d1": A die has between 2 and 1000 sides, 1 given.'];
        yield 'empty dice' => [[self::ranged('weather', dice: '')], 'Oracle table "weather" has invalid dice "": The dice expression is empty'];
        yield 'min greater than max' => [[self::ranged('weather', [['min' => 4, 'max' => 3, 'text' => 'A']])], 'Entry 1 of oracle table "weather" has a min (4) greater than its max (3).'];
        yield 'only min' => [[self::ranged('weather', [['min' => 1, 'text' => 'A']])], 'Entry 1 of oracle table "weather" has a "min" but no "max"; a range has both.'];
        yield 'only max' => [[self::ranged('weather', [['max' => 1, 'text' => 'A']])], 'Entry 1 of oracle table "weather" has a "max" but no "min"; a range has both.'];
        yield 'overlapping ranges' => [[self::ranged('weather', [['min' => 1, 'max' => 3, 'text' => 'A'], ['min' => 3, 'max' => 6, 'text' => 'B']])], 'Entries 1 (1 to 3) and 2 (3 to 6) of oracle table "weather" overlap.'];
        yield 'overlapping unsorted ranges' => [[self::ranged('weather', [['min' => 4, 'max' => 6, 'text' => 'A'], ['min' => 5, 'max' => 5, 'text' => 'B'], ['min' => 1, 'max' => 3, 'text' => 'C']])], 'Entries 1 (4 to 6) and 2 (5 to 5) of oracle table "weather" overlap.'];
        yield 'contained range' => [[self::ranged('weather', [['min' => 1, 'max' => 6, 'text' => 'A'], ['min' => 2, 'max' => 3, 'text' => 'B'], ['min' => 5, 'max' => 5, 'text' => 'C']])], 'Entries 1 (1 to 6) and 2 (2 to 3) of oracle table "weather" overlap.'];

        // Mixing ranged and weighted entries
        yield 'entry with range and weight' => [[self::ranged('weather', [['min' => 1, 'max' => 6, 'weight' => 2, 'text' => 'A']])], 'Entry 1 of oracle table "weather" has both a range and a weight; a table\'s entries are either all ranged or all weighted.'];
        yield 'weighted entry in ranged table' => [[self::ranged('weather', [['min' => 1, 'max' => 3, 'text' => 'A'], ['weight' => 2, 'text' => 'B']])], 'Entry 2 of oracle table "weather" has no range, but the table has dice; a table\'s entries are either all ranged or all weighted.'];
        yield 'plain entry in ranged table' => [[self::ranged('weather', [['text' => 'A']])], 'Entry 1 of oracle table "weather" has no range, but the table has dice'];
        yield 'ranged entry in weighted table' => [[self::weighted('mood', [['text' => 'A'], ['min' => 1, 'max' => 3, 'text' => 'B']])], 'Entry 2 of oracle table "mood" has a range, but the table has no dice; a table\'s entries are either all ranged or all weighted.'];

        // Weighted tables
        yield 'zero weight' => [[self::weighted('mood', [['weight' => 0, 'text' => 'A']])], 'Entry 1 of oracle table "mood" has a weight of at least 1, 0 given.'];
        yield 'negative weight' => [[self::weighted('mood', [['weight' => -2, 'text' => 'A']])], 'Entry 1 of oracle table "mood" has a weight of at least 1, -2 given.'];
        yield 'total weight over 1,000,000' => [[self::weighted('mood', [['weight' => 500_000, 'text' => 'A'], ['weight' => 500_001, 'text' => 'B']])], 'Oracle table "mood" has a total weight of at most 1000000, 1000001 given.'];
        yield 'huge weights' => [[self::weighted('mood', [['weight' => \PHP_INT_MAX, 'text' => 'A'], ['weight' => \PHP_INT_MAX, 'text' => 'B']])], 'Oracle table "mood" has a total weight of at most 1000000'];

        // Nesting
        yield 'unknown nested key' => [[self::weighted('mood', [['text' => 'A', 'table' => 'missing']])], 'Entry 1 of oracle table "mood" nests "missing", but there is no oracle table "missing" in the table set.'];
        yield 'invalid nested key' => [[self::weighted('mood', [['text' => 'A', 'table' => 'Not A Key']])], 'An oracle table key has 1 to 64 characters among a-z, 0-9 and "-", "Not A Key" given.'];
        yield 'self nesting' => [[self::weighted('mood', [['text' => 'A', 'table' => 'mood']])], 'Oracle tables nest in a cycle: "mood" > "mood".'];
        yield 'cycle' => [[
            self::weighted('a', [['text' => 'Stop'], ['text' => 'B', 'table' => 'b']]),
            self::weighted('b', [['text' => 'C', 'table' => 'c']]),
            self::weighted('c', [['text' => 'A', 'table' => 'a']]),
        ], 'Oracle tables nest in a cycle: "a" > "b" > "c" > "a".'];
        yield 'cycle below the root' => [[
            self::weighted('root', [['text' => 'A', 'table' => 'a']]),
            self::weighted('a', [['text' => 'B', 'table' => 'b']]),
            self::weighted('b', [['text' => 'A', 'table' => 'a']]),
        ], 'Oracle tables nest in a cycle: "a" > "b" > "a".'];
        yield 'nesting 11 levels deep' => [self::chain(12), 'Oracle tables nest at most 10 levels deep; "t1" nests 11 levels.'];
    }

    /**
     * @param array<mixed> $tables
     */
    #[Test]
    #[DataProvider('invalidDefinitions')]
    public function itRejectsInvalidDefinitions(array $tables, string $message): void
    {
        $this->expectException(InvalidOracleTable::class);
        $this->expectExceptionMessageIsOrContains($message);

        OracleTableSet::fromArray($tables);
    }
}
