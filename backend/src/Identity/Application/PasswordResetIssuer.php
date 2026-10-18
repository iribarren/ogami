<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\PasswordReset;
use App\Identity\Domain\PasswordResetId;
use App\Identity\Domain\PasswordResetRepository;
use App\Identity\Domain\User;

/**
 * Gives an active user a new reset link: what RequestPasswordReset and SendPasswordReset share.
 */
final readonly class PasswordResetIssuer
{
    public function __construct(
        private PasswordResetRepository $resets,
        private TokenGenerator $tokens,
        private AccountLinks $links,
        private AccountMailer $mailer,
        private Clock $clock,
    ) {
    }

    /**
     * Saves the new reset, deletes the user's other resets (their links stop working), then
     * queues the mail, which never leaves for a refused command.
     *
     * @throws PasswordResetIdAlreadyInUse
     * @throws MailUnavailable
     */
    public function issue(PasswordResetId $id, User $user): void
    {
        if ($this->resets->ofId($id) instanceof PasswordReset) {
            throw PasswordResetIdAlreadyInUse::for($id);
        }

        $token = $this->tokens->generate();
        $reset = PasswordReset::request($id, $user->id(), $token, $this->clock->now());
        $this->resets->save($reset);
        $this->resets->deleteForUserExcept($user->id(), $id);

        $this->mailer->sendPasswordReset($user->email(), $this->links->passwordReset($token), $reset->expiresAt());
    }
}
