<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Invitation;
use App\Identity\Domain\InvitationRepository;
use App\Identity\Domain\User;
use App\Identity\Domain\UserId;
use App\Identity\Domain\UserRepository;
use App\Shared\Application\Bus\CommandHandler;

final readonly class AcceptInvitationHandler implements CommandHandler
{
    public function __construct(
        private InvitationRepository $invitations,
        private InvitationLoader $loader,
        private UserRepository $users,
        private PasswordHasher $hasher,
        private Clock $clock,
    ) {
    }

    /**
     * The user and the accepted invitation are saved in the command's transaction, so
     * either both are or neither is.
     *
     * @throws WeakPassword
     * @throws InvalidAccountLink
     * @throws EmailAlreadyInUse  also when a concurrent request wins the race (the repository translates the unique index violation)
     * @throws UserIdAlreadyInUse
     */
    public function __invoke(AcceptInvitation $command): void
    {
        PasswordPolicy::assertAcceptable($command->password);

        $invitation = $this->loader->usableForToken($command->token);
        $id = UserId::fromString($command->userId);
        $email = $invitation->email();

        if ($this->users->ofEmail($email) instanceof User) {
            throw EmailAlreadyInUse::for($email);
        }

        if ($this->users->ofId($id) instanceof User) {
            throw UserIdAlreadyInUse::for($id);
        }

        $now = $this->clock->now();
        $this->users->save(User::register($id, $email, $this->hasher->hash($command->password), $invitation->roles(), $now));
        $invitation->accept($now);
        $this->invitations->save($invitation);
    }
}
