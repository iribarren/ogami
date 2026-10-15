<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

use App\Studio\Domain\Release\InvalidReleaseContent;

/**
 * A GameSystem's key, owner or metadata breaks the release contract's rules. Names the field.
 */
final class InvalidGameSystemMetadata extends \DomainException
{
    private function __construct(
        public readonly string $field,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function at(string $field, string $reason): self
    {
        return new self($field, \sprintf('%s: %s', $field, $reason));
    }

    public static function fromContract(string $field, InvalidReleaseContent $rejection): self
    {
        return new self($field, $rejection->getMessage(), $rejection);
    }
}
