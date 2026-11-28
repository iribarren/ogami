<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Query;

/**
 * Asks for the whole content of a published release and the card images it pinned. Part of the
 * Published Language other contexts (Play) use; it reads published releases only, never a draft
 * (ADR 0010), and checks no owner: the asking context decides who may see it.
 *
 * @implements Query<PublishedReleaseContentView>
 */
final readonly class GetPublishedReleaseContent implements Query
{
    public function __construct(
        public string $gameSystemKey,
        public int $version,
    ) {
    }
}
