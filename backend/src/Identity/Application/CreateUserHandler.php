<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Email;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Domain\UserId;
use App\Identity\Domain\UserRepository;

final readonly class CreateUserHandler
{
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $hasher,
    ) {
    }

    public function __invoke(CreateUser $command): void
    {
        $email = Email::fromString($command->email);

        if ('' === trim($command->plainPassword)) {
            throw PasswordMustNotBeEmpty::create();
        }

        $roles = array_map(
            static fn (string $value): Role => Role::tryFrom($value) ?? throw UnknownRole::named($value),
            $command->roles,
        );

        if ($this->users->ofEmail($email) instanceof User) {
            throw EmailAlreadyInUse::for($email);
        }

        $this->users->save(User::register(
            UserId::fromString($command->userId),
            $email,
            $this->hasher->hash($command->plainPassword),
            $roles,
        ));
    }
}
