<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem;

/**
 * A published release follows a schema version Play cannot read (yet).
 */
final class UnsupportedReleaseSchemaVersion extends \RuntimeException
{
    /**
     * @param list<int> $supported
     */
    public static function of(string $gameSystemKey, int $version, int $schemaVersion, array $supported): self
    {
        return new self(\sprintf(
            'GameSystem "%s" v%d uses schema version %d; Play supports schema version(s) %s.',
            $gameSystemKey,
            $version,
            $schemaVersion,
            implode(', ', $supported),
        ));
    }
}
