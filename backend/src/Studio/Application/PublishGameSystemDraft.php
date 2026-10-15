<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Command;

/**
 * Publishes the draft of the GameSystem with $key, owned by $ownerId, as the next release of that
 * key, with optional release notes. $releaseId comes from a ReleaseIdGenerator; ask
 * GetPublishedReleaseById afterwards for the release it made.
 */
final readonly class PublishGameSystemDraft implements Command
{
    public function __construct(
        public string $ownerId,
        public string $key,
        public string $releaseId,
        public ?string $notes,
    ) {
    }
}
