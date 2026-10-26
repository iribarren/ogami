<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

/**
 * The machine-readable cause of a point budget route's 409. The values are the HTTP contract
 * (ADR 0037, 0043): the SPA branches on them, so they never change with the wording of a message.
 */
enum SheetConflictCode: string
{
    case ModifiedConcurrently = 'modified_concurrently';
    case LibraryPack = 'library_pack';
    case SeveralPointBudgets = 'several_point_budgets';
}
