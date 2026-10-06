<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * Published contract: the signed-in user as other contexts see it. Their HTTP controllers take it
 * with Symfony's #[CurrentUser], without depending on Identity's security adapter.
 */
interface AuthenticatedUser
{
    /**
     * The user's id, as the domain User knows it.
     */
    public function id(): string;
}
