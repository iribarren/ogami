<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * The outcome of checking valid GameSystem release content: what it would publish and the authoring
 * warnings, which never prevent publishing and are not stored.
 */
final readonly class GameSystemReleaseCheck
{
    /**
     * @param list<string> $warnings each starting with the path it is about
     */
    public function __construct(
        public string $gameSystemKey,
        public int $schemaVersion,
        public array $warnings,
    ) {
    }
}
