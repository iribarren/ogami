<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\InvitationId;

/**
 * Port: hands out a new, unique InvitationId.
 */
interface InvitationIdGenerator
{
    public function generate(): InvitationId;
}
