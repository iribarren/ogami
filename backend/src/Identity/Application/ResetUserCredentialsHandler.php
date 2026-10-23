<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Email;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Shared\Application\Bus\CommandHandler;

final readonly class ResetUserCredentialsHandler implements CommandHandler
{
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $hasher,
    ) {
    }

    /**
     * All or nothing: every input is checked before the user changes, and the user is saved once.
     *
     * @throws UserNotFound
     */
    public function __invoke(ResetUserCredentials $command): void
    {
        $email = Email::fromString($command->email);

        if ('' === trim($command->plainPassword)) {
            throw PasswordMustNotBeEmpty::create();
        }

        $roles = array_map(
            static fn (string $value): Role => Role::tryFrom($value) ?? throw UnknownRole::named($value),
            $command->roles,
        );

        $user = $this->users->ofEmail($email);
        if (!$user instanceof User) {
            throw UserNotFound::withEmail($email->toString());
        }

        // Refuses an empty role list before anything else changes.
        $user->changeRoles($roles);
        $user->changePassword($this->hasher->hash($command->plainPassword));
        $user->enable();
        $this->users->save($user);
    }
}
