<?php

declare(strict_types=1);

namespace App\Formula\Domain;

/**
 * What a {@see Reference} reads.
 */
enum ReferenceKind
{
    /** `{key}`: a number field, a derived value, or the current value of a track field. */
    case Field;

    /** `{key.max}`: the max of a track field. */
    case TrackMax;

    /** `{tracker:key}`: the value of a Campaign tracker. */
    case Tracker;
}
