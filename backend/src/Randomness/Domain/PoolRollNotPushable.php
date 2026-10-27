<?php

declare(strict_types=1);

namespace App\Randomness\Domain;

/**
 * A pool roll cannot be pushed: a push re-rolls the regular dice that failed (never Hunger dice, ADR 0044), and none did.
 */
final class PoolRollNotPushable extends \DomainException
{
    public static function noRegularDieFailed(): self
    {
        return new self('A pool roll cannot be pushed when no regular die failed.');
    }
}
