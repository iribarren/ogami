<?php

declare(strict_types=1);

namespace App\Randomness\Domain\Oracle;

/**
 * The chaos factor of a likelihood oracle: each point above neutral raises the target by
 * shiftPerPoint, each point below lowers it.
 *
 * Bounds stay within ±1000 and the shift within the die's sides, so a shift never overflows.
 */
final readonly class LikelihoodChaos
{
    public const int MAX_BOUND = 1000;

    /**
     * @throws InvalidLikelihoodOracle
     */
    public function __construct(
        private int $min,
        private int $max,
        private int $neutral,
        private int $shiftPerPoint,
        int $sides,
    ) {
        if ($min < -self::MAX_BOUND || $max > self::MAX_BOUND) {
            throw InvalidLikelihoodOracle::chaosBoundsOutOfRange($min, $max, self::MAX_BOUND);
        }

        if ($min > $neutral || $neutral > $max) {
            throw InvalidLikelihoodOracle::chaosNotOrdered($min, $neutral, $max);
        }

        if ($shiftPerPoint < 0 || $shiftPerPoint > $sides) {
            throw InvalidLikelihoodOracle::shiftOutOfRange($shiftPerPoint, $sides);
        }
    }

    public function min(): int
    {
        return $this->min;
    }

    public function max(): int
    {
        return $this->max;
    }

    public function neutral(): int
    {
        return $this->neutral;
    }

    public function shiftPerPoint(): int
    {
        return $this->shiftPerPoint;
    }

    /**
     * How far this chaos factor moves a target: (factor − neutral) × shiftPerPoint.
     *
     * @throws InvalidLikelihoodOracle when the factor is outside min…max
     */
    public function shift(int $factor): int
    {
        if ($factor < $this->min || $factor > $this->max) {
            throw InvalidLikelihoodOracle::chaosFactorOutOfRange($factor, $this->min, $this->max);
        }

        return ($factor - $this->neutral) * $this->shiftPerPoint;
    }
}
