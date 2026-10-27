<?php

declare(strict_types=1);

namespace App\Studio\Domain\Check;

use App\Randomness\Domain\PoolRoll;

/**
 * The special result a test-rolled pool can have (ADR 0044), at most one per roll. The value is what
 * the API carries. Studio cannot import Play, so this mirrors Play's SpecialResult and its rule.
 */
enum CheckTestSpecialResult: string
{
    case Critical = 'critical';
    case MessyCritical = 'messyCritical';
    case BestialFailure = 'bestialFailure';

    /**
     * The special result of a roll, from the facts of its dice (ADR 0044). A roll that failed with a
     * Hunger die showing 1 is a bestial failure; one that did not fail is a messy critical with a
     * critical pair holding a Hunger die, else a critical with a critical pair. The dice only state
     * facts a pool declares, so a pool without critical pairs or Hunger dice never has one.
     *
     * @param bool $failed whether the roll failed: below the difficulty when the check declares one, else in the first of two bands or more
     */
    public static function of(PoolRoll $roll, bool $failed): ?self
    {
        return match (true) {
            $failed => $roll->hasHungerOne() ? self::BestialFailure : null,
            $roll->hasHungerCriticalPair() => self::MessyCritical,
            $roll->hasCriticalPair() => self::Critical,
            default => null,
        };
    }
}
