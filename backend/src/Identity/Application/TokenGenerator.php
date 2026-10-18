<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\AccountToken;

/**
 * Port: makes the unguessable token of an account link.
 */
interface TokenGenerator
{
    public function generate(): AccountToken;
}
