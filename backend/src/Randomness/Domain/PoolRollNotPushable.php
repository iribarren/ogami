<?php

declare(strict_types=1);

namespace App\Randomness\Domain;

/**
 * A pool roll cannot be pushed: a push re-rolls the dice that failed, and none did.
 */
final class PoolRollNotPushable extends \DomainException
{
    public static function everyDieSucceeded(): self
    {
        return new self('A pool roll cannot be pushed when every die succeeded.');
    }
}
