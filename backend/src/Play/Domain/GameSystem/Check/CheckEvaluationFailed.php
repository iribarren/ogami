<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Check;

/**
 * A check cannot be performed for this Character: its modifier or a band bound fails to evaluate
 * (division by zero, overflow, a value the sheet lacks), or its dice cannot be evaluated. Nothing
 * was rolled for a formula that fails.
 */
final class CheckEvaluationFailed extends \DomainException
{
    public static function of(string $checkKey, \Throwable $reason): self
    {
        return new self(\sprintf('Check "%s" cannot be performed: %s', $checkKey, $reason->getMessage()), previous: $reason);
    }
}
