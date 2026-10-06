<?php

declare(strict_types=1);

namespace App\Play\Application;

/**
 * The latest published release of a GameSystem, as Play offers it when a campaign is created.
 */
final readonly class GameSystemSummary
{
    public function __construct(
        public string $gameSystemKey,
        public string $name,
        public ?string $description,
        public int $version,
    ) {
    }
}
