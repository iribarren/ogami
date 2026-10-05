<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release;

/**
 * The content of a GameSystem release breaks the contract. The message starts with the path of
 * the offending value, e.g. "oracles.likelihood[1].key: …"; "(root)" names the document itself.
 */
final class InvalidReleaseContent extends \DomainException
{
    public static function at(string $path, string $reason, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('%s: %s', $path, $reason), 0, $previous);
    }
}
