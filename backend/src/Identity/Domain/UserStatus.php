<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Whether a user may sign in. A disabled user keeps their data and content
 * but cannot authenticate (ADR 0034).
 */
enum UserStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}
