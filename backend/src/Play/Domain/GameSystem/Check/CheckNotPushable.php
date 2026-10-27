<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Check;

/**
 * A check cannot be pushed: it declares no push, or its roll has no failed regular die to re-roll.
 */
final class CheckNotPushable extends \DomainException
{
    public static function noPush(string $key): self
    {
        return new self(\sprintf('Check "%s" declares no push.', $key));
    }

    public static function noFailedDie(string $key): self
    {
        return new self(\sprintf('Check "%s" cannot be pushed: every die of the roll succeeded.', $key));
    }
}
