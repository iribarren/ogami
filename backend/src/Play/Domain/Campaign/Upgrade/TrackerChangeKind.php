<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Upgrade;

/**
 * What a release upgrade does to a stored Tracker value: it loses it when the Tracker is gone
 * (removed), or moves it inside the new bounds (clamped). A new Tracker needs no change: nothing is
 * stored, so it reads as its initial value.
 */
enum TrackerChangeKind: string
{
    case Removed = 'removed';
    case Clamped = 'clamped';
}
