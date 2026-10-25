<?php

declare(strict_types=1);

namespace App\Studio\Domain\Check;

/**
 * A sandbox roll cannot be pushed: the check declares no push (a dice check never does), or no die of
 * the roll failed. As Play's push refuses, a conflict with the roll or the check, not a bad value.
 */
final class CheckTestRollNotPushable extends \DomainException
{
    public static function noPush(string $checkKey): self
    {
        return new self(\sprintf('Check "%s" cannot be pushed: it declares no push.', $checkKey));
    }

    public static function noFailedDie(string $checkKey): self
    {
        return new self(\sprintf('Check "%s" cannot be pushed: no die failed.', $checkKey));
    }
}
