<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Check;

/**
 * A GameSystem snapshot has no check with the key asked for.
 */
final class UnknownCheck extends \DomainException
{
    public static function withKey(string $gameSystemKey, int $version, string $key): self
    {
        return new self(\sprintf('GameSystem "%s" v%d has no check "%s".', $gameSystemKey, $version, $key));
    }
}
