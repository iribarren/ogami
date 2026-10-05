<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Query;

/**
 * Asks for the published GameSystem release with this id, or null when there is none (e.g. a
 * publish with onlyIfChanged found the content unchanged and published nothing).
 *
 * @implements Query<PublishedReleaseView|null>
 */
final readonly class GetPublishedReleaseById implements Query
{
    public function __construct(
        public string $releaseId,
    ) {
    }
}
