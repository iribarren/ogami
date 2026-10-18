<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * The acting user tried to lock themselves out: disabling their own account or
 * dropping their own OWNER role. That keeps at least one active owner (ADR 0034).
 */
final class OwnAccountProtected extends \DomainException
{
    public static function cannotDisable(): self
    {
        return new self('You cannot disable your own account.');
    }

    public static function cannotLoseOwnerRole(): self
    {
        return new self('You cannot remove the OWNER role from your own account.');
    }
}
