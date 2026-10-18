<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Someone who can sign in: an email, a password hash, one or more independent
 * roles, a status (active or disabled) and the date it was created.
 *
 * Roles can be replaced, the account disabled or enabled and the password
 * changed; id, email and creation date never change. Identifiers and roles are
 * kept as scalars (strings and a list of role values) so persistence can map
 * them without custom types; the getters expose value objects.
 */
final class User
{
    /**
     * @param non-empty-list<string> $roles Role values, without duplicates
     */
    private function __construct(
        private readonly string $id,
        private readonly string $email,
        private string $passwordHash,
        private array $roles,
        private UserStatus $status,
        // Not readonly: Doctrine refreshes managed users in place (UserRepository::activeOwnersForUpdate)
        // and cannot re-assign a readonly object property, even to an equal date. No method changes it.
        private \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param list<Role> $roles
     *
     * @throws UserMustHaveARole
     * @throws InvalidPasswordHash
     */
    public static function register(UserId $id, Email $email, string $passwordHash, array $roles, \DateTimeImmutable $createdAt): self
    {
        if ('' === $passwordHash) {
            throw InvalidPasswordHash::blank();
        }

        return new self($id->toString(), $email->toString(), $passwordHash, self::roleValues($roles), UserStatus::Active, $createdAt);
    }

    /**
     * Replaces all roles; an empty list is refused and the roles stay as they were.
     *
     * @param list<Role> $roles
     *
     * @throws UserMustHaveARole
     */
    public function changeRoles(array $roles): void
    {
        $this->roles = self::roleValues($roles);
    }

    /**
     * Idempotent.
     */
    public function disable(): void
    {
        $this->status = UserStatus::Disabled;
    }

    /**
     * Idempotent.
     */
    public function enable(): void
    {
        $this->status = UserStatus::Active;
    }

    /**
     * @param string $hash an already hashed password
     *
     * @throws InvalidPasswordHash
     */
    public function changePassword(string $hash): void
    {
        if ('' === $hash) {
            throw InvalidPasswordHash::blank();
        }

        $this->passwordHash = $hash;
    }

    public function id(): UserId
    {
        return UserId::fromString($this->id);
    }

    public function email(): Email
    {
        return Email::fromString($this->email);
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    /**
     * @return list<Role>
     *
     * @throws UnknownStoredRole when a stored row holds a role that no longer exists
     */
    public function roles(): array
    {
        return array_map(Role::fromStored(...), $this->roles);
    }

    public function hasRole(Role $role): bool
    {
        return \in_array($role->value, $this->roles, true);
    }

    public function status(): UserStatus
    {
        return $this->status;
    }

    public function isDisabled(): bool
    {
        return UserStatus::Disabled === $this->status;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @param list<Role> $roles
     *
     * @return non-empty-list<string>
     *
     * @throws UserMustHaveARole
     */
    private static function roleValues(array $roles): array
    {
        $values = array_values(array_unique(array_map(static fn (Role $role): string => $role->value, $roles)));
        if ([] === $values) {
            throw UserMustHaveARole::none();
        }

        return $values;
    }
}
