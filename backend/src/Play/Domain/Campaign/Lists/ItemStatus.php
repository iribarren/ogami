<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Lists;

/**
 * Whether an NPC or Thread is still in play. Only open items are picked and counted.
 */
enum ItemStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
