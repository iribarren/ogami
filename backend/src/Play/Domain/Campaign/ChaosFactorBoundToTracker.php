<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

/**
 * A likelihood oracle whose chaos is bound to a Tracker takes the campaign's value of that Tracker
 * as its chaos factor, so a chaos factor sent with the question is refused.
 */
final class ChaosFactorBoundToTracker extends \DomainException
{
    public static function for(string $oracleKey, string $trackerKey): self
    {
        return new self(\sprintf('Likelihood oracle "%s" takes its chaos factor from tracker "%s": send no chaos factor.', $oracleKey, $trackerKey));
    }
}
