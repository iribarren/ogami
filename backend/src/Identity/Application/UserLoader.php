<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\InvalidUserId;
use App\Identity\Domain\User;
use App\Identity\Domain\UserId;
use App\Identity\Domain\UserRepository;

/**
 * Finds the user a command targets: an unknown or malformed id is "not found".
 * Also tells whether a change would remove the last active owner.
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

    /**
     * Whether the target is an active owner and no other active owner remains.
     * Locks every active user row (see UserRepository::activeOwnersForUpdate),
     * so call it only when the change removes the target's owner standing.
     */
    public function isLastActiveOwner(User $target): bool
    {
        $owners = $this->users->activeOwnersForUpdate();
        $others = array_filter($owners, static fn (User $owner): bool => !$owner->id()->equals($target->id()));

        return [] === $others && \count($owners) > 0;
    }
}
