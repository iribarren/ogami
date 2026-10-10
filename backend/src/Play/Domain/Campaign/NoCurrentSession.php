<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

/**
 * No session is under way (none has started, or the latest one has ended), so nothing that belongs
 * to a session (a scene) can start and no session can end.
 */
final class NoCurrentSession extends \DomainException
{
    public static function toStartScene(): self
    {
        return new self('Start a session before starting a scene.');
    }

    public static function toEndSession(): self
    {
        return new self('No session is under way: start a session before ending one.');
    }
}
