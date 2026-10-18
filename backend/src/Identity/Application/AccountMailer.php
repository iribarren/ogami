<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Email;
use App\Identity\Domain\Role;

/**
 * Port: sends the emails of account links. Implementations send only after the
 * command that asked for them has committed, and fail with MailUnavailable.
 */
interface AccountMailer
{
    /**
     * @param list<Role> $roles the roles the invited user will get
     *
     * @throws MailUnavailable
     */
    public function sendInvitation(Email $email, string $link, array $roles, \DateTimeImmutable $expiresAt): void;

    /**
     * @throws MailUnavailable
     */
    public function sendPasswordReset(Email $email, string $link, \DateTimeImmutable $expiresAt): void;
}
