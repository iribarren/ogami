<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * The secret behind an invitation or password reset link. Only its sha256 hash is
 * stored, so a token is shown once (in the link) and never kept or logged.
 */
final readonly class AccountToken
{
    private function __construct(
        private string $value,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the value is blank
     */
    public static function fromString(#[\SensitiveParameter] string $value): self
    {
        if ('' === $value) {
            throw new \InvalidArgumentException('An account token cannot be blank.');
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    /**
     * Lower case sha256 hex: what is stored and looked up.
     */
    public function hash(): string
    {
        return hash('sha256', $this->value);
    }
}
