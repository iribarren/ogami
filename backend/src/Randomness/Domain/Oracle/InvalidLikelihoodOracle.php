<?php

declare(strict_types=1);

namespace App\Randomness\Domain\Oracle;

/**
 * A likelihood oracle definition breaks a rule, or a question asked of it is invalid.
 */
final class InvalidLikelihoodOracle extends \DomainException
{
    public static function missingSides(): self
    {
        return new self('A likelihood oracle has a "sides" integer.');
    }

    public static function sidesOutOfRange(int $sides, int $min, int $max): self
    {
        return new self(\sprintf('A likelihood oracle rolls a die of %d to %d sides, %d given.', $min, $max, $sides));
    }

    public static function missingLevels(): self
    {
        return new self('A likelihood oracle has a "levels" list.');
    }

    public static function levelCountOutOfRange(int $count, int $max): self
    {
        return new self(\sprintf('A likelihood oracle has between 1 and %d levels, %d given.', $max, $count));
    }

    public static function levelNotAnObject(int $position): self
    {
        return new self(\sprintf('Likelihood level %d is not an object.', $position));
    }

    public static function missingLevelKey(int $position): self
    {
        return new self(\sprintf('Likelihood level %d has no "key" string.', $position));
    }

    public static function missingLabel(string $level): self
    {
        return new self(\sprintf('Likelihood level "%s" has no "label" string.', $level));
    }

    public static function missingTarget(string $level): self
    {
        return new self(\sprintf('Likelihood level "%s" has no "target" integer.', $level));
    }

    public static function invalidLevelKey(string $key, int $max): self
    {
        return new self(\sprintf('A likelihood level key has 1 to %d characters among a-z, 0-9 and "-", "%s" given.', $max, $key));
    }

    public static function duplicateLevelKey(string $key): self
    {
        return new self(\sprintf('Two likelihood levels have the key "%s"; keys are unique within a likelihood oracle.', $key));
    }

    public static function labelLength(string $level, int $length, int $max): self
    {
        return new self(\sprintf('Likelihood level "%s" has a label of 1 to %d characters, %d given.', $level, $max, $length));
    }

    public static function targetOutOfRange(string $level, int $target, int $sides): self
    {
        return new self(\sprintf('Likelihood level "%s" has a target of 0 to %d, %d given.', $level, $sides, $target));
    }

    public static function exceptionalPercentNotAnInteger(): self
    {
        return new self('The "exceptionalPercent" of a likelihood oracle is an integer.');
    }

    public static function exceptionalPercentOutOfRange(int $percent, int $max): self
    {
        return new self(\sprintf('A likelihood oracle has an exceptional percent of 0 to %d, %d given.', $max, $percent));
    }

    public static function chaosNotAnObject(): self
    {
        return new self('The "chaos" of a likelihood oracle is an object.');
    }

    public static function missingChaosField(string $field): self
    {
        return new self(\sprintf('The chaos of a likelihood oracle has a "%s" integer.', $field));
    }

    public static function chaosBoundsOutOfRange(int $min, int $max, int $bound): self
    {
        return new self(\sprintf('The chaos factor bounds are between %d and %d, %d to %d given.', -$bound, $bound, $min, $max));
    }

    public static function chaosNotOrdered(int $min, int $neutral, int $max): self
    {
        return new self(\sprintf('The chaos factor has min ≤ neutral ≤ max, %d ≤ %d ≤ %d given.', $min, $neutral, $max));
    }

    public static function shiftOutOfRange(int $shift, int $sides): self
    {
        return new self(\sprintf('The chaos factor shifts the target by 0 to %d points per step, %d given.', $sides, $shift));
    }

    /**
     * @param list<string> $levels the keys of the oracle's levels
     */
    public static function unknownLevel(string $level, array $levels): self
    {
        return new self(\sprintf('There is no likelihood level "%s"; the levels are "%s".', $level, implode('", "', $levels)));
    }

    public static function chaosFactorOutOfRange(int $factor, int $min, int $max): self
    {
        return new self(\sprintf('The chaos factor is between %d and %d, %d given.', $min, $max, $factor));
    }

    public static function unexpectedChaosFactor(int $factor): self
    {
        return new self(\sprintf('This likelihood oracle has no chaos factor, %d given.', $factor));
    }
}
