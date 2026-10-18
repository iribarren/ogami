<?php

declare(strict_types=1);

namespace App\Tests\Support\Identity;

use App\Identity\Domain\Email;
use App\Identity\Domain\Invitation;
use App\Identity\Domain\InvitationId;
use App\Identity\Domain\InvitationRepository;

final class InMemoryInvitationRepository implements InvitationRepository
{
    /** @var array<string, Invitation> */
    private array $invitations = [];

    public function save(Invitation $invitation): void
    {
        $this->invitations[$invitation->id()->toString()] = $invitation;
    }

    public function ofId(InvitationId $id): ?Invitation
    {
        return $this->invitations[$id->toString()] ?? null;
    }

    public function pendingForEmail(Email $email): ?Invitation
    {
        foreach ($this->invitations as $invitation) {
            if ($invitation->isPending() && $invitation->email()->equals($email)) {
                return $invitation;
            }
        }

        return null;
    }

    public function ofTokenHash(string $tokenHash): ?Invitation
    {
        foreach ($this->invitations as $invitation) {
            if ($invitation->tokenHash() === $tokenHash) {
                return $invitation;
            }
        }

        return null;
    }

    /**
     * @return list<Invitation>
     */
    public function all(): array
    {
        return array_values($this->invitations);
    }
}
