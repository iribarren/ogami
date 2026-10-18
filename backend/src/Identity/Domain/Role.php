<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * What a user may do (ADR 0006). Roles are independent: no role implies another,
 * so each product area checks its own role.
 */
enum Role: string
{
    case SoloPlayer = 'SOLO_PLAYER';
    case GameManager = 'GAME_MANAGER';
    case Owner = 'OWNER';

    /**
     * Reads a role back from a stored row, which is not trusted.
     *
     * @throws UnknownStoredRole
     */
    public static function fromStored(string $value): self
    {
        return self::tryFrom($value) ?? throw UnknownStoredRole::named($value);
    }
}
