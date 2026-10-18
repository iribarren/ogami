<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * No invitation with this id can be acted on: unknown, malformed, or no longer pending.
 */
final class InvitationNotFound extends \RuntimeException
{
    public static function withId(string $id): self
    {
        return new self(\sprintf('No pending invitation with id "%s".', $id));
    }
}
