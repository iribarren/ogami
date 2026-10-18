<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Port: where users are kept.
 */
interface UserRepository
{
    public function save(User $user): void;

    public function ofId(UserId $id): ?User;

    public function ofEmail(Email $email): ?User;

    /**
     * Every user, ordered by email.
     *
     * @return list<User>
     */
    public function all(): array;

    /**
     * Every active user holding OWNER, read with a write lock (ordered by id, so
     * concurrent callers lock in the same order) and with fresh state even for
     * users already loaded. Needs an open transaction.
     *
     * @return list<User>
     */
    public function activeOwnersForUpdate(): array;
}
