<?php

declare(strict_types=1);

namespace App\Studio\Domain\Check;

/**
 * A check cannot be test-rolled as saved: a formula or the dice fail to evaluate (division by zero,
 * overflow, a value the sheet lacks), or the stored draft is not readable. Nothing was rolled for a
 * formula that fails, as in Play.
 */
final class CheckTestRollFailed extends \DomainException
{
    public static function evaluation(string $checkKey, \Throwable $reason): self
    {
        return new self(\sprintf('Check "%s" cannot be test-rolled: %s', $checkKey, $reason->getMessage()), previous: $reason);
    }

    /**
     * @param string $what what is wrong with the stored draft, e.g. "check dice are not a string"
     */
    public static function unreadable(string $what, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('The saved draft cannot be test-rolled: %s.', $what), previous: $previous);
    }
}
