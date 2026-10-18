<?php

declare(strict_types=1);

namespace App\Tests\Support\Identity;

use App\Identity\Application\InvitationAlreadyPending;
use App\Identity\Domain\Email;
use App\Identity\Domain\Invitation;
use App\Identity\Domain\InvitationId;
use App\Identity\Domain\InvitationRepository;

final class InMemoryInvitationRepository implements InvitationRepository
{
    /** @var array<string, Invitation> */
    private array $invitations = [];

    /**
     * @throws InvitationAlreadyPending like the database, whose partial unique index allows one pending invitation per email
     */
    public function save(Invitation $invitation): void
    {
        $other = $this->pendingForEmail($invitation->email());
        if ($invitation->isPending() && $other instanceof Invitation && !$other->id()->equals($invitation->id())) {
            throw InvitationAlreadyPending::for($invitation->email());
        }

        $this->invitations[$invitation->id()->toString()] = $invitation;
    }

    public function ofId(InvitationId $id): ?Invitation
    {
        return $this->invitations[$id->toString()] ?? null;
    }

    public function ofIdForUpdate(InvitationId $id): ?Invitation
    {
        return $this->ofId($id);
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

    public function pending(): array
    {
        $pending = array_values(array_filter($this->invitations, static fn (Invitation $invitation): bool => $invitation->isPending()));
        usort($pending, static fn (Invitation $a, Invitation $b): int => strcmp($a->email()->toString(), $b->email()->toString()));

        return $pending;
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

    public function ofTokenHashForUpdate(string $tokenHash): ?Invitation
    {
        return $this->ofTokenHash($tokenHash);
    }

    /**
     * @return list<Invitation>
     */
    public function all(): array
    {
        return array_values($this->invitations);
    }
}
