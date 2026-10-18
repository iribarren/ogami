<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Shared\Application\Bus\Command;

/**
 * Sends a pending invitation again: a new link replaces the old one and the invitation is valid for a full period from now.
 */
final readonly class ResendInvitation implements Command
{
    public function __construct(
        public string $invitationId,
    ) {
    }
}
