<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem;

/**
 * A GameSystem snapshot has no oracle with the key asked for.
 */
final class UnknownGameSystemOracle extends \DomainException
{
    public static function table(string $gameSystemKey, int $version, string $key): self
    {
        return new self(\sprintf('GameSystem "%s" v%d has no oracle table "%s".', $gameSystemKey, $version, $key));
    }

    public static function likelihood(string $gameSystemKey, int $version, string $key): self
    {
        return new self(\sprintf('GameSystem "%s" v%d has no likelihood oracle "%s".', $gameSystemKey, $version, $key));
    }
}
