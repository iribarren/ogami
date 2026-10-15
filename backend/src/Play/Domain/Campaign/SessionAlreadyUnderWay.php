<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

/**
 * A session is still under way, so another cannot start: one session is open at a time and it
 * has to end first.
 */
final class SessionAlreadyUnderWay extends \DomainException
{
    public static function numbered(int $number): self
    {
        return new self(\sprintf('Session %d is still under way; end it before starting another.', $number));
    }
}
