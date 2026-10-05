<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Query;

/**
 * Asks for a published GameSystem release: the given version, or the latest when $version is null.
 * Part of the Published Language other contexts (Play) use to read GameSystems.
 *
 * @implements Query<PublishedReleaseView>
 */
final readonly class GetPublishedRelease implements Query
{
    public function __construct(
        public string $gameSystemKey,
        public ?int $version = null,
    ) {
    }
}
