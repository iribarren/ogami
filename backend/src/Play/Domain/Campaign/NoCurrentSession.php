<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

/**
 * The campaign has no session yet, so nothing that belongs to a session (a scene) can start.
 */
final class NoCurrentSession extends \DomainException
{
    public static function toStartScene(): self
    {
        return new self('Start a session before starting a scene.');
    }
}
