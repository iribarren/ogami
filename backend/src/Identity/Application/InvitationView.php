<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Invitation;
use App\Identity\Domain\Role;

/**
 * A pending invitation as the owner sees it: never the token or its hash.
 */
final readonly class InvitationView
{
    /**
     * @param list<string> $roles   Role values, e.g. "SOLO_PLAYER"
     * @param bool         $expired the link no longer works; resending renews it
     */
    public function __construct(
        public string $id,
        public string $email,
        public array $roles,
        public string $invitedBy,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $expiresAt,
        public bool $expired,
    ) {
    }

    public static function fromInvitation(Invitation $invitation, \DateTimeImmutable $now): self
    {
        return new self(
            $invitation->id()->toString(),
            $invitation->email()->toString(),
            array_map(static fn (Role $role): string => $role->value, $invitation->roles()),
            $invitation->invitedBy()->toString(),
            $invitation->createdAt(),
            $invitation->expiresAt(),
            $invitation->isExpiredAt($now),
        );
    }
}
