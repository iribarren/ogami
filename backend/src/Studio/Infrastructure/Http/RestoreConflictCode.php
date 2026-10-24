<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

/**
 * The machine-readable cause of a restore 409. The values are the HTTP contract (ADR 0037): the SPA
 * branches on them, so they never change with the wording of a message.
 */
enum RestoreConflictCode: string
{
    case ModifiedConcurrently = 'modified_concurrently';
    case LibraryPack = 'library_pack';
}
