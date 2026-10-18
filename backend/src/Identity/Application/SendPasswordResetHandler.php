<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\PasswordResetId;
use App\Shared\Application\Bus\CommandHandler;

final readonly class SendPasswordResetHandler implements CommandHandler
{
    public function __construct(
        private UserLoader $loader,
        private PasswordResetIssuer $issuer,
    ) {
    }

    /**
     * @throws UserNotFound
     * @throws UserDisabled
     * @throws PasswordResetIdAlreadyInUse
     * @throws MailUnavailable
     */
    public function __invoke(SendPasswordReset $command): void
    {
        $id = PasswordResetId::fromString($command->resetId);
        $user = $this->loader->load($command->userId);
        if ($user->isDisabled()) {
            throw UserDisabled::withId($user->id());
        }

        $this->issuer->issue($id, $user);
    }
}
