<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Shared\Application\Bus\Command;

/**
 * The invited person follows the link and chooses a password: the account is created
 * with the invitation's roles. The caller picks the user id (see UserIdGenerator).
 */
final readonly class AcceptInvitation implements Command
{
    public function __construct(
        #[\SensitiveParameter]
        public string $token,
        #[\SensitiveParameter]
        public string $password,
        public string $userId,
    ) {
    }
}
