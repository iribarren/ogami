<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Shared\Application\Bus\Query;

/**
 * Asks Play for its snapshot of a published GameSystem release: the given version, or the latest
 * when $version is null.
 *
 * @implements Query<GameSystemSnapshot>
 */
final readonly class GetGameSystemSnapshot implements Query
{
    public function __construct(
        public string $gameSystemKey,
        public ?int $version = null,
    ) {
    }
}
