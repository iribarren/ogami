<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Email;
use App\Identity\Domain\InvalidEmail;
use App\Identity\Domain\PasswordReset;
use App\Identity\Domain\PasswordResetId;
use App\Identity\Domain\PasswordResetRepository;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Shared\Application\Bus\CommandHandler;

final readonly class RequestPasswordResetHandler implements CommandHandler
{
    public function __construct(
        private UserRepository $users,
        private PasswordResetRepository $resets,
        private PasswordResetIssuer $issuer,
    ) {
    }

    /**
     * An unknown or disabled account does nothing and says nothing: the answer must not reveal
     * which emails have an account. For the same reason a reused reset id is refused before the
     * account is looked up (the issuer checks it again, for the owner's SendPasswordReset).
     *
     * @throws InvalidEmail
     * @throws PasswordResetIdAlreadyInUse
     * @throws MailUnavailable
     */
    public function __invoke(RequestPasswordReset $command): void
    {
        $id = PasswordResetId::fromString($command->resetId);
        if ($this->resets->ofId($id) instanceof PasswordReset) {
            throw PasswordResetIdAlreadyInUse::for($id);
        }

        $user = $this->users->ofEmail(Email::fromString($command->email));
        if (!$user instanceof User || $user->isDisabled()) {
            return;
        }

        $this->issuer->issue($id, $user);
    }
}
