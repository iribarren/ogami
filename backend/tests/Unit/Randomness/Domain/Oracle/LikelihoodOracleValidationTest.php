<?php

declare(strict_types=1);

namespace App\Tests\Unit\Randomness\Domain\Oracle;

use App\Randomness\Domain\Oracle\InvalidLikelihoodOracle;
use App\Randomness\Domain\Oracle\LikelihoodChaos;
use App\Randomness\Domain\Oracle\LikelihoodLevel;
use App\Randomness\Domain\Oracle\LikelihoodOracle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LikelihoodOracle::class)]
#[CoversClass(LikelihoodLevel::class)]
#[CoversClass(LikelihoodChaos::class)]
#[CoversClass(InvalidLikelihoodOracle::class)]
final class LikelihoodOracleValidationTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function definition(array $overrides = []): array
    {
        return [...[
            'sides' => 100,
            'levels' => [
                ['key' => 'unlikely', 'label' => 'Unlikely', 'target' => 35],
                ['key' => 'likely', 'label' => 'Likely', 'target' => 65],
            ],
            'chaos' => ['min' => 1, 'max' => 9, 'neutral' => 5, 'shiftPerPoint' => 5],
            'exceptionalPercent' => 20,
        ], ...$overrides];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function withChaos(array $overrides): array
    {
        return self::definition(['chaos' => [...['min' => 1, 'max' => 9, 'neutral' => 5, 'shiftPerPoint' => 5], ...$overrides]]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function withLevel(mixed $level): array
    {
        return self::definition(['levels' => [$level]]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function levels(int $count): array
    {
        return array_map(static fn (int $i): array => ['key' => 'level-'.$i, 'label' => 'Level '.$i, 'target' => $i], range(1, $count));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function definitionsAtTheLimits(): iterable
    {
        yield 'the example definition' => [self::definition()];
        yield 'two sides' => [self::definition(['sides' => 2, 'levels' => [['key' => 'coin', 'label' => 'Coin', 'target' => 1]], 'chaos' => null])];
        yield '1000 sides' => [self::definition(['sides' => 1000])];
        yield 'one level' => [self::definition(['levels' => self::levels(1)])];
        yield '20 levels' => [self::definition(['levels' => self::levels(20)])];
        yield 'target 0' => [self::withLevel(['key' => 'never', 'label' => 'Never', 'target' => 0])];
        yield 'target equal to the sides' => [self::withLevel(['key' => 'always', 'label' => 'Always', 'target' => 100])];
        yield '64-character level key' => [self::withLevel(['key' => str_repeat('a', 64), 'label' => 'Long', 'target' => 1])];
        yield '500-character label' => [self::withLevel(['key' => 'long', 'label' => str_repeat('é', 500), 'target' => 1])];
        yield 'no chaos' => [self::definition(['chaos' => null])];
        yield 'no exceptional percent' => [self::definition(['exceptionalPercent' => null])];
        yield 'exceptional percent 0' => [self::definition(['exceptionalPercent' => 0])];
        yield 'exceptional percent 50' => [self::definition(['exceptionalPercent' => 50])];
        yield 'neutral at the chaos minimum' => [self::withChaos(['neutral' => 1])];
        yield 'neutral at the chaos maximum' => [self::withChaos(['neutral' => 9])];
        yield 'single-value chaos' => [self::withChaos(['min' => 5, 'max' => 5])];
        yield 'negative chaos bounds' => [self::withChaos(['min' => -1000, 'neutral' => 0, 'max' => 1000])];
        yield 'no chaos shift' => [self::withChaos(['shiftPerPoint' => 0])];
        yield 'chaos shift equal to the sides' => [self::withChaos(['shiftPerPoint' => 100])];
    }

    /**
     * @param array<string, mixed> $definition
     */
    #[Test]
    #[DataProvider('definitionsAtTheLimits')]
    public function itAcceptsDefinitionsAtTheLimits(array $definition): void
    {
        self::assertNotEmpty(LikelihoodOracle::fromArray($definition)->levels());
    }

    #[Test]
    public function itExposesItsDefinition(): void
    {
        $oracle = LikelihoodOracle::fromArray(self::definition(['levels' => [['key' => 'likely', 'label' => '  Likely  ', 'target' => 65]]]));

        self::assertSame(100, $oracle->sides());
        self::assertSame(['likely'], array_map(static fn (LikelihoodLevel $level): string => $level->key(), $oracle->levels()));
        self::assertSame('Likely', $oracle->level('likely')->label());
        self::assertSame(65, $oracle->level('likely')->target());
        self::assertSame(20, $oracle->exceptionalPercent());

        $chaos = $oracle->chaos();
        self::assertInstanceOf(LikelihoodChaos::class, $chaos);
        self::assertSame([1, 9, 5, 5], [$chaos->min(), $chaos->max(), $chaos->neutral(), $chaos->shiftPerPoint()]);
        self::assertNull(LikelihoodOracle::fromArray(self::definition(['chaos' => null]))->chaos());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidDefinitions(): iterable
    {
        yield 'no sides' => [self::definition(['sides' => null]), 'A likelihood oracle has a "sides" integer.'];
        yield 'sides not an integer' => [self::definition(['sides' => '100']), 'A likelihood oracle has a "sides" integer.'];
        yield 'one side' => [self::definition(['sides' => 1]), 'A likelihood oracle rolls a die of 2 to 1000 sides, 1 given.'];
        yield '1001 sides' => [self::definition(['sides' => 1001]), 'A likelihood oracle rolls a die of 2 to 1000 sides, 1001 given.'];
        yield 'no levels' => [self::definition(['levels' => null]), 'A likelihood oracle has a "levels" list.'];
        yield 'levels not a list' => [self::definition(['levels' => ['likely' => ['key' => 'likely', 'label' => 'Likely', 'target' => 65]]]), 'A likelihood oracle has a "levels" list.'];
        yield 'empty levels' => [self::definition(['levels' => []]), 'A likelihood oracle has between 1 and 20 levels, 0 given.'];
        yield '21 levels' => [self::definition(['levels' => self::levels(21)]), 'A likelihood oracle has between 1 and 20 levels, 21 given.'];
        yield 'level not an object' => [self::withLevel('likely'), 'Likelihood level 1 is not an object.'];
        yield 'level without key' => [self::withLevel(['label' => 'Likely', 'target' => 65]), 'Likelihood level 1 has no "key" string.'];
        yield 'level key not a string' => [self::withLevel(['key' => 7, 'label' => 'Likely', 'target' => 65]), 'Likelihood level 1 has no "key" string.'];
        yield 'invalid level key' => [self::withLevel(['key' => 'Very likely', 'label' => 'Likely', 'target' => 65]), 'A likelihood level key has 1 to 64 characters among a-z, 0-9 and "-", "Very likely" given.'];
        yield 'empty level key' => [self::withLevel(['key' => '', 'label' => 'Likely', 'target' => 65]), 'A likelihood level key has 1 to 64 characters among a-z, 0-9 and "-", "" given.'];
        yield '65-character level key' => [self::withLevel(['key' => str_repeat('a', 65), 'label' => 'Likely', 'target' => 65]), 'A likelihood level key has 1 to 64 characters'];
        yield 'duplicate level key' => [self::definition(['levels' => [['key' => 'likely', 'label' => 'Likely', 'target' => 65], ['key' => 'likely', 'label' => 'Again', 'target' => 70]]]), 'Two likelihood levels have the key "likely"; keys are unique within a likelihood oracle.'];
        yield 'level without label' => [self::withLevel(['key' => 'likely', 'target' => 65]), 'Likelihood level "likely" has no "label" string.'];
        yield 'blank label' => [self::withLevel(['key' => 'likely', 'label' => '   ', 'target' => 65]), 'Likelihood level "likely" has a label of 1 to 500 characters, 0 given.'];
        yield '501-character label' => [self::withLevel(['key' => 'likely', 'label' => str_repeat('é', 501), 'target' => 65]), 'Likelihood level "likely" has a label of 1 to 500 characters, 501 given.'];
        yield 'level without target' => [self::withLevel(['key' => 'likely', 'label' => 'Likely']), 'Likelihood level "likely" has no "target" integer.'];
        yield 'target not an integer' => [self::withLevel(['key' => 'likely', 'label' => 'Likely', 'target' => 65.5]), 'Likelihood level "likely" has no "target" integer.'];
        yield 'negative target' => [self::withLevel(['key' => 'likely', 'label' => 'Likely', 'target' => -1]), 'Likelihood level "likely" has a target of 0 to 100, -1 given.'];
        yield 'target above the sides' => [self::withLevel(['key' => 'likely', 'label' => 'Likely', 'target' => 101]), 'Likelihood level "likely" has a target of 0 to 100, 101 given.'];
        yield 'exceptional percent not an integer' => [self::definition(['exceptionalPercent' => '20']), 'The "exceptionalPercent" of a likelihood oracle is an integer.'];
        yield 'negative exceptional percent' => [self::definition(['exceptionalPercent' => -1]), 'A likelihood oracle has an exceptional percent of 0 to 50, -1 given.'];
        yield 'exceptional percent above 50' => [self::definition(['exceptionalPercent' => 51]), 'A likelihood oracle has an exceptional percent of 0 to 50, 51 given.'];
        yield 'chaos not an object' => [self::definition(['chaos' => 5]), 'The "chaos" of a likelihood oracle is an object.'];
        yield 'chaos without min' => [self::definition(['chaos' => ['max' => 9, 'neutral' => 5, 'shiftPerPoint' => 5]]), 'The chaos of a likelihood oracle has a "min" integer.'];
        yield 'chaos max not an integer' => [self::withChaos(['max' => '9']), 'The chaos of a likelihood oracle has a "max" integer.'];
        yield 'chaos without neutral' => [self::definition(['chaos' => ['min' => 1, 'max' => 9, 'shiftPerPoint' => 5]]), 'The chaos of a likelihood oracle has a "neutral" integer.'];
        yield 'chaos without shift' => [self::definition(['chaos' => ['min' => 1, 'max' => 9, 'neutral' => 5]]), 'The chaos of a likelihood oracle has a "shiftPerPoint" integer.'];
        yield 'chaos min above max' => [self::withChaos(['min' => 9, 'max' => 1]), 'The chaos factor has min ≤ neutral ≤ max, 9 ≤ 5 ≤ 1 given.'];
        yield 'neutral below min' => [self::withChaos(['neutral' => 0]), 'The chaos factor has min ≤ neutral ≤ max, 1 ≤ 0 ≤ 9 given.'];
        yield 'neutral above max' => [self::withChaos(['neutral' => 10]), 'The chaos factor has min ≤ neutral ≤ max, 1 ≤ 10 ≤ 9 given.'];
        yield 'chaos min out of bounds' => [self::withChaos(['min' => -1001]), 'The chaos factor bounds are between -1000 and 1000, -1001 to 9 given.'];
        yield 'chaos max out of bounds' => [self::withChaos(['max' => 1001]), 'The chaos factor bounds are between -1000 and 1000, 1 to 1001 given.'];
        yield 'negative chaos shift' => [self::withChaos(['shiftPerPoint' => -1]), 'The chaos factor shifts the target by 0 to 100 points per step, -1 given.'];
        yield 'chaos shift above the sides' => [self::withChaos(['shiftPerPoint' => 101]), 'The chaos factor shifts the target by 0 to 100 points per step, 101 given.'];
    }

    /**
     * @param array<string, mixed> $definition
     */
    #[Test]
    #[DataProvider('invalidDefinitions')]
    public function itRejectsInvalidDefinitions(array $definition, string $message): void
    {
        $this->expectException(InvalidLikelihoodOracle::class);
        $this->expectExceptionMessageIsOrContains($message);

        LikelihoodOracle::fromArray($definition);
    }
}
