<?php

declare(strict_types=1);

namespace App\Tests\Support\Identity;

use App\Identity\Application\AccountMailer;
use App\Identity\Domain\Email;

/**
 * Keeps the account emails it was asked to send instead of sending them.
 */
final class SpyAccountMailer implements AccountMailer
{
    /** @var list<array{email: string, link: string, roles: list<string>, expiresAt: \DateTimeImmutable}> */
    public array $invitations = [];

    /** @var list<array{email: string, link: string, expiresAt: \DateTimeImmutable}> */
    public array $passwordResets = [];

    public function sendInvitation(Email $email, string $link, array $roles, \DateTimeImmutable $expiresAt): void
    {
        $this->invitations[] = [
            'email' => $email->toString(),
            'link' => $link,
            'roles' => array_map(static fn (\App\Identity\Domain\Role $role): string => $role->value, $roles),
            'expiresAt' => $expiresAt,
        ];
    }

    public function sendPasswordReset(Email $email, string $link, \DateTimeImmutable $expiresAt): void
    {
        $this->passwordResets[] = ['email' => $email->toString(), 'link' => $link, 'expiresAt' => $expiresAt];
    }
}
