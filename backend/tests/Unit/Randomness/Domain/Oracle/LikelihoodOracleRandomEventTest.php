<?php

declare(strict_types=1);

namespace App\Tests\Unit\Randomness\Domain\Oracle;

use App\Randomness\Domain\Oracle\InvalidLikelihoodOracle;
use App\Randomness\Domain\Oracle\LikelihoodAnswer;
use App\Randomness\Domain\Oracle\LikelihoodOracle;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LikelihoodOracle::class)]
#[CoversClass(LikelihoodAnswer::class)]
#[CoversClass(InvalidLikelihoodOracle::class)]
final class LikelihoodOracleRandomEventTest extends TestCase
{
    /**
     * A d100 oracle with a 1–9 chaos factor (neutral 5) and a random event rule.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function definition(array $overrides = []): array
    {
        return [...[
            'sides' => 100,
            'levels' => [['key' => 'likely', 'label' => 'Likely', 'target' => 65]],
            'chaos' => ['min' => 1, 'max' => 9, 'neutral' => 5, 'shiftPerPoint' => 5],
            'randomEvent' => ['table' => 'random-event-focus'],
        ], ...$overrides];
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function ask(array $definition, ?int $chaosFactor, int $roll): LikelihoodAnswer
    {
        return LikelihoodOracle::fromArray($definition)->ask('likely', $chaosFactor, new ScriptedRandomNumberGenerator($roll));
    }

    /**
     * Every double against the chaos factors around its digit: the digit equal to the chaos factor
     * triggers, one above does not.
     *
     * @return iterable<string, array{int, int, bool}>
     */
    public static function doubles(): iterable
    {
        for ($digit = 1; $digit <= 9; ++$digit) {
            foreach (range(1, 9) as $chaos) {
                yield \sprintf('double %d with chaos %d', $digit * 11, $chaos) => [$digit * 11, $chaos, $digit <= $chaos];
            }
        }
    }

    #[Test]
    #[DataProvider('doubles')]
    public function aDoubleTriggersTheEventWhenItsDigitIsAtMostTheChaosFactor(int $roll, int $chaos, bool $triggers): void
    {
        self::assertSame($triggers ? 'random-event-focus' : null, $this->ask(self::definition(), $chaos, $roll)->randomEvent());
    }

    #[Test]
    public function theNeutralChaosFactorIsUsedWhenNoneIsGiven(): void
    {
        self::assertSame('random-event-focus', $this->ask(self::definition(), null, 55)->randomEvent());
        self::assertNull($this->ask(self::definition(), null, 66)->randomEvent());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function nonDoubles(): iterable
    {
        yield 'a hundred' => [100];
        yield 'one below a double' => [10];
        yield 'one above a double' => [12];
        yield 'a low roll' => [1];
        yield 'a high roll' => [99 - 1];
        yield 'a multiple of ten' => [50];
    }

    #[Test]
    #[DataProvider('nonDoubles')]
    public function otherRollsNeverTrigger(int $roll): void
    {
        self::assertNull($this->ask(self::definition(), 9, $roll)->randomEvent());
    }

    #[Test]
    public function anOracleWithoutTheRuleNeverTriggers(): void
    {
        self::assertNull($this->ask(self::definition(['randomEvent' => null]), 9, 11)->randomEvent());
        self::assertNull($this->ask(array_diff_key(self::definition(), ['randomEvent' => true]), 9, 11)->randomEvent());
    }

    #[Test]
    public function theRuleDoesNotChangeTheAnswer(): void
    {
        $with = $this->ask(self::definition(), 5, 33);
        $without = $this->ask(self::definition(['randomEvent' => null]), 5, 33);

        self::assertSame($without->answer(), $with->answer());
        self::assertSame($without->effectiveTarget(), $with->effectiveTarget());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidRules(): iterable
    {
        yield 'not an object' => [self::definition(['randomEvent' => 'random-event-focus']), 'The "randomEvent" of a likelihood oracle is an object.'];
        yield 'a list' => [self::definition(['randomEvent' => ['random-event-focus']]), 'The "randomEvent" of a likelihood oracle is an object.'];
        yield 'no table' => [self::definition(['randomEvent' => []]), 'The "randomEvent" of a likelihood oracle has a "table" key.'];
        yield 'blank table' => [self::definition(['randomEvent' => ['table' => '  ']]), 'The "randomEvent" of a likelihood oracle has a "table" key.'];
        yield 'table not a string' => [self::definition(['randomEvent' => ['table' => 3]]), 'The "randomEvent" of a likelihood oracle has a "table" key.'];
        yield 'extra field' => [self::definition(['randomEvent' => ['table' => 'focus', 'when' => 'always']]), 'The "randomEvent" of a likelihood oracle has no "when" field.'];
        yield 'no chaos' => [self::definition(['chaos' => null]), 'A random event needs the chaos factor of a likelihood oracle.'];
        yield 'not a d100' => [self::definition(['sides' => 20, 'levels' => [['key' => 'likely', 'label' => 'Likely', 'target' => 10]]]), 'A random event needs a likelihood oracle of 100 sides, 20 given.'];
    }

    /**
     * @param array<string, mixed> $definition
     */
    #[Test]
    #[DataProvider('invalidRules')]
    public function itRejectsMalformedRules(array $definition, string $message): void
    {
        $this->expectException(InvalidLikelihoodOracle::class);
        $this->expectExceptionMessageIsOrContains($message);

        LikelihoodOracle::fromArray($definition);
    }
}
