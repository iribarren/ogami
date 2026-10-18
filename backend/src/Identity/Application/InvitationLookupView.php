<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Invitation;
use App\Identity\Domain\Role;

/**
 * What the invited person sees before choosing a password: who is invited, as what, until when.
 */
final readonly class InvitationLookupView
{
    /**
     * @param list<string> $roles Role values, e.g. "SOLO_PLAYER"
     */
    public function __construct(
        public string $email,
        public array $roles,
        public \DateTimeImmutable $expiresAt,
    ) {
    }

    public static function fromInvitation(Invitation $invitation): self
    {
        return new self(
            $invitation->email()->toString(),
            array_map(static fn (Role $role): string => $role->value, $invitation->roles()),
            $invitation->expiresAt(),
        );
    }
}
