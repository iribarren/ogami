<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * A request to choose a new password: for which user, until when, and whether it was
 * used. It holds only the hash of the token sent in the link.
 *
 * A reset is usable once, within an hour of being requested; a newer request for the
 * same user replaces it (see PasswordResetRepository::deleteForUserExcept).
 */
final class PasswordReset
{
    /** How long a reset link works. */
    public const string VALIDITY = 'PT1H';

    // The dates are not readonly: Doctrine refreshes a locked reset in place
    // (PasswordResetRepository::ofTokenHashForUpdate) and cannot re-assign a readonly object property,
    // even to an equal date. Only use() changes usedAt.
    private function __construct(
        private readonly string $id,
        private readonly string $userId,
        private readonly string $tokenHash,
        private \DateTimeImmutable $createdAt,
        private \DateTimeImmutable $expiresAt,
        private ?\DateTimeImmutable $usedAt,
    ) {
    }

    public static function request(PasswordResetId $id, UserId $userId, AccountToken $token, \DateTimeImmutable $now): self
    {
        return new self(
            $id->toString(),
            $userId->toString(),
            $token->hash(),
            $now,
            $now->add(new \DateInterval(self::VALIDITY)),
            null,
        );
    }

    /**
     * The user chose a new password: the reset is used up.
     *
     * @throws PasswordResetNotUsable when it was already used or has expired; it stays as it was
     */
    public function use(\DateTimeImmutable $now): void
    {
        if ($this->usedAt instanceof \DateTimeImmutable) {
            throw PasswordResetNotUsable::alreadyUsed($this->usedAt);
        }

        if ($now >= $this->expiresAt) {
            throw PasswordResetNotUsable::expired($this->expiresAt);
        }

        $this->usedAt = $now;
    }

    /**
     * Not used and not yet expired: it expires exactly at expiresAt().
     */
    public function isUsableAt(\DateTimeImmutable $now): bool
    {
        return !$this->usedAt instanceof \DateTimeImmutable && $now < $this->expiresAt;
    }

    public function id(): PasswordResetId
    {
        return PasswordResetId::fromString($this->id);
    }

    public function userId(): UserId
    {
        return UserId::fromString($this->userId);
    }

    public function tokenHash(): string
    {
        return $this->tokenHash;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function usedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }
}
