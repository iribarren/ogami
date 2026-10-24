<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

/**
 * The machine-readable cause of a publish 409. The values are the HTTP contract (ADR 0037): the SPA
 * branches on them, so they never change with the wording of a message.
 */
enum PublishConflictCode: string
{
    case NoChanges = 'no_changes';
    case ReleaseExists = 'release_exists';
    case LibraryPack = 'library_pack';
}
