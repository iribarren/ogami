<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * An offer to create an account: who is invited (email), with which roles, until
 * when, and by whom. It holds only the hash of the token sent in the link.
 *
 * It starts pending; later it is accepted (the account exists) or revoked. A pending
 * invitation past its expiry date stays pending but can no longer be used.
 */
final class Invitation
{
    /** How long an invitation can be accepted. */
    public const string VALIDITY = 'P7D';

    /**
     * @param list<string> $roles Role values, without duplicates; never empty when built here, but a stored row is
     *                            not trusted (see roles())
     */
    private function __construct(
        private readonly string $id,
        private readonly string $email,
        private readonly array $roles,
        private readonly string $tokenHash,
        private readonly string $invitedBy,
        private readonly \DateTimeImmutable $createdAt,
        private readonly \DateTimeImmutable $expiresAt,
        private InvitationStatus $status,
    ) {
    }

    /**
     * @param list<Role> $roles
     *
     * @throws InvitationMustHaveARole
     */
    public static function invite(
        InvitationId $id,
        Email $email,
        array $roles,
        AccountToken $token,
        UserId $invitedBy,
        \DateTimeImmutable $now,
    ): self {
        $values = array_values(array_unique(array_map(static fn (Role $role): string => $role->value, $roles)));
        if ([] === $values) {
            throw InvitationMustHaveARole::none();
        }

        return new self(
            $id->toString(),
            $email->toString(),
            $values,
            $token->hash(),
            $invitedBy->toString(),
            $now,
            $now->add(new \DateInterval(self::VALIDITY)),
            InvitationStatus::Pending,
        );
    }

    /**
     * @throws InvitationNotPending
     */
    public function revoke(): void
    {
        if (InvitationStatus::Pending !== $this->status) {
            throw InvitationNotPending::cannotRevoke($this->status);
        }

        $this->status = InvitationStatus::Revoked;
    }

    /**
     * The invitation can no longer be accepted from this instant on.
     */
    public function isExpiredAt(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function isPending(): bool
    {
        return InvitationStatus::Pending === $this->status;
    }

    public function id(): InvitationId
    {
        return InvitationId::fromString($this->id);
    }

    public function email(): Email
    {
        return Email::fromString($this->email);
    }

    /**
     * @return non-empty-list<Role>
     *
     * @throws InvitationMustHaveARole when a stored row has no role: it must not load silently
     */
    public function roles(): array
    {
        if ([] === $this->roles) {
            throw InvitationMustHaveARole::none();
        }

        return array_map(Role::from(...), $this->roles);
    }

    public function tokenHash(): string
    {
        return $this->tokenHash;
    }

    public function invitedBy(): UserId
    {
        return UserId::fromString($this->invitedBy);
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function status(): InvitationStatus
    {
        return $this->status;
    }
}
