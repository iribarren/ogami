<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release;

/**
 * The content of a GameSystem release breaks the contract. The message starts with the path of
 * the offending value, e.g. "oracles.likelihood[1].key: …"; "(root)" names the document itself.
 * path() and reason() give the two parts of the message.
 */
final class InvalidReleaseContent extends \DomainException
{
    private string $path = '';
    private string $reason = '';

    public static function at(string $path, string $reason, ?\Throwable $previous = null): self
    {
        $error = new self(\sprintf('%s: %s', $path, $reason), 0, $previous);
        $error->path = $path;
        $error->reason = $reason;

        return $error;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
