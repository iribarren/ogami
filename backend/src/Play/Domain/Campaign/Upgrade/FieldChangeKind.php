<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Upgrade;

/**
 * What a release upgrade does to one stored Character value (ADR 0035): a new field gets its
 * initial value (added), a gone or now derived field loses its value (removed), a number or track
 * is moved inside its new bounds (clamped), a list over its new limit keeps its first items
 * (trimmed), a conditions field loses the marks of options it no longer declares (trimmed), a value that
 * cannot be fitted takes the new initial value (reset), and a list becoming an inventory (or the other
 * way round), or an inventory stack whose gear the target no longer accepts, keeps what it can (converted,
 * ADR 0045).
 */
enum FieldChangeKind: string
{
    case Added = 'added';
    case Removed = 'removed';
    case Clamped = 'clamped';
    case Trimmed = 'trimmed';
    case Reset = 'reset';
    case Converted = 'converted';
}
