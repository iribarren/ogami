<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Shared\Application\Bus\Command;

/**
 * Withdraws a pending invitation; its link stops working.
 */
final readonly class RevokeInvitation implements Command
{
    public function __construct(
        public string $invitationId,
    ) {
    }
}
