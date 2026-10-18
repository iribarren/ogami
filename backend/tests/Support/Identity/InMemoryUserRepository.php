<?php

declare(strict_types=1);

namespace App\Tests\Support\Identity;

use App\Identity\Domain\Email;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Domain\UserId;
use App\Identity\Domain\UserRepository;

final class InMemoryUserRepository implements UserRepository
{
    /** @var array<string, User> */
    private array $users = [];

    /** How many times the active owners were read for update. */
    public int $ownerLocks = 0;

    public function save(User $user): void
    {
        $this->users[$user->id()->toString()] = $user;
    }

    public function ofId(UserId $id): ?User
    {
        return $this->users[$id->toString()] ?? null;
    }

    public function ofEmail(Email $email): ?User
    {
        foreach ($this->users as $user) {
            if ($user->email()->equals($email)) {
                return $user;
            }
        }

        return null;
    }

    public function all(): array
    {
        $users = array_values($this->users);
        usort($users, static fn (User $a, User $b): int => strcmp($a->email()->toString(), $b->email()->toString()));

        return $users;
    }

    public function activeOwnersForUpdate(): array
    {
        ++$this->ownerLocks;

        return array_values(array_filter(
            $this->all(),
            static fn (User $user): bool => !$user->isDisabled() && $user->hasRole(Role::Owner),
        ));
    }

    public function count(): int
    {
        return \count($this->users);
    }
}
