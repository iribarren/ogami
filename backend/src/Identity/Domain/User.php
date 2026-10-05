<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Someone who can sign in: an email, a password hash and one or more
 * independent roles.
 *
 * State is kept as scalars (strings and a list of role values) so persistence
 * can map it without custom types; the getters expose value objects.
 */
final readonly class User
{
    /**
     * @param non-empty-list<string> $roles Role values, without duplicates
     */
    private function __construct(
        private string $id,
        private string $email,
        private string $passwordHash,
        private array $roles,
    ) {
    }

    /**
     * @param list<Role> $roles
     *
     * @throws UserMustHaveARole
     * @throws InvalidPasswordHash
     */
    public static function register(UserId $id, Email $email, string $passwordHash, array $roles): self
    {
        if ('' === $passwordHash) {
            throw InvalidPasswordHash::blank();
        }

        $values = array_values(array_unique(array_map(static fn (Role $role): string => $role->value, $roles)));
        if ([] === $values) {
            throw UserMustHaveARole::none();
        }

        return new self($id->toString(), $email->toString(), $passwordHash, $values);
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
     */
    public function roles(): array
    {
        return array_map(Role::from(...), $this->roles);
    }

    public function hasRole(Role $role): bool
    {
        return \in_array($role->value, $this->roles, true);
    }
}
