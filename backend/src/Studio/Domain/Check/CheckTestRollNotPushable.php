<?php

declare(strict_types=1);

namespace App\Studio\Domain\Check;

/**
 * A sandbox roll cannot be pushed: the check declares no push (a dice check never does), no regular die
 * of the roll failed (a Hunger die is never re-rolled, ADR 0044), or the momentum of a challenge roll beats no more dice than its score. As Play's push refuses, a conflict with the roll or the check, not a bad value.
 */
final class CheckTestRollNotPushable extends \DomainException
{
    public static function noPush(string $checkKey): self
    {
        return new self(\sprintf('Check "%s" cannot be pushed: it declares no push.', $checkKey));
    }

    public static function noFailedDie(string $checkKey): self
    {
        return new self(\sprintf('Check "%s" cannot be pushed: no regular die failed.', $checkKey));
    }

    public static function noBetterBurn(string $checkKey): self
    {
        return new self(\sprintf('Check "%s" cannot burn momentum: it beats no more challenge dice than the score does.', $checkKey));
    }
}
