<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Email;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Domain\UserId;
use App\Identity\Domain\UserRepository;
use App\Shared\Application\Bus\CommandHandler;

final readonly class CreateUserHandler implements CommandHandler
{
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $hasher,
    ) {
    }

    /**
     * @throws EmailAlreadyInUse also when a concurrent request wins the race (the repository translates the unique index violation)
     */
    public function __invoke(CreateUser $command): void
    {
        $id = UserId::fromString($command->userId);
        $email = Email::fromString($command->email);

        if ('' === trim($command->plainPassword)) {
            throw PasswordMustNotBeEmpty::create();
        }

        $roles = array_map(
            static fn (string $value): Role => Role::tryFrom($value) ?? throw UnknownRole::named($value),
            $command->roles,
        );

        if ($this->users->ofId($id) instanceof User) {
            throw UserIdAlreadyInUse::for($id);
        }

        if ($this->users->ofEmail($email) instanceof User) {
            throw EmailAlreadyInUse::for($email);
        }

        $this->users->save(User::register(
            $id,
            $email,
            $this->hasher->hash($command->plainPassword),
            $roles,
        ));
    }
}
