<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure\Http;

/**
 * The role values as the Admin API documents them. Roles cross the context boundary as strings
 * (Admin never imports Identity's Domain), and the Identity handlers refuse any other value.
 */
final readonly class AdminRoles
{
    public const array VALUES = ['SOLO_PLAYER', 'GAME_MANAGER', 'OWNER'];
}
