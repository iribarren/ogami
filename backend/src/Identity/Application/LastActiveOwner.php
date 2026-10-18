<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * The change would leave the app without an active owner: the target is the only
 * one left. Unlike OwnAccountProtected it also covers two owners acting on each
 * other at the same time (ADR 0034).
 */
final class LastActiveOwner extends \DomainException
{
    public static function cannotDisable(): self
    {
        return new self('The last active owner cannot be disabled.');
    }

    public static function cannotLoseOwnerRole(): self
    {
        return new self('The OWNER role cannot be removed from the last active owner.');
    }
}
