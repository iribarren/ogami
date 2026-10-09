<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Query;

/**
 * Validates a GameSystem release file without publishing it and tells its authoring warnings
 * (docs/contracts/gamesystem-release.md, "Authoring warning"). The command bus returns nothing, so
 * this is how a publisher learns the warnings of what it publishes: ask it with the same content.
 *
 * @implements Query<GameSystemReleaseCheck>
 */
final readonly class CheckGameSystemRelease implements Query
{
    /**
     * @param array<mixed> $content decoded release JSON, objects as string-keyed arrays
     */
    public function __construct(
        public array $content,
    ) {
    }
}
