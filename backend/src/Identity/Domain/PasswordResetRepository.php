<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Port: where password resets are kept.
 */
interface PasswordResetRepository
{
    public function save(PasswordReset $reset): void;

    public function ofId(PasswordResetId $id): ?PasswordReset;

    /**
     * Locks the row until the command's transaction ends and returns its current state, so two
     * uses of the same link run one after the other.
     *
     * @param string $tokenHash see AccountToken::hash()
     */
    public function ofTokenHashForUpdate(string $tokenHash): ?PasswordReset;

    /**
     * Deletes every reset of the user (used, expired or not) but the given one.
     */
    public function deleteForUserExcept(UserId $userId, PasswordResetId $keep): void;
}
