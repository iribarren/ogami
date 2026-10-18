<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * Where an imported item stands against its library pack (ADR 0033). Computed when asked, never
 * stored.
 */
enum LibraryImportStatus: string
{
    /** The pack item is the one that was imported. */
    case Current = 'current';

    /** The pack item differs from the one that was imported. */
    case Changed = 'changed';

    /** The pack, or its item, is gone. */
    case Removed = 'removed';
}
