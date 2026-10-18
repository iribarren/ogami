<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\InvalidUserId;
use App\Identity\Domain\User;
use App\Identity\Domain\UserId;
use App\Identity\Domain\UserRepository;

/**
 * Finds the user a command targets: an unknown or malformed id is "not found".
 */
final readonly class UserLoader
{
    public function __construct(
        private UserRepository $users,
    ) {
    }

    /**
     * @throws UserNotFound
     */
    public function load(string $id): User
    {
        try {
            $user = $this->users->ofId(UserId::fromString($id));
        } catch (InvalidUserId) {
            $user = null;
        }

        return $user ?? throw UserNotFound::withId($id);
    }

    /**
     * Whether the acting user and the target are the same person.
     */
    public static function isSelf(string $actorId, User $target): bool
    {
        return $actorId === $target->id()->toString();
    }
}
