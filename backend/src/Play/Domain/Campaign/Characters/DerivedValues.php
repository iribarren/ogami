<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Characters;

use App\Play\Domain\GameSystem\Sheet\SheetTemplate;

/**
 * The DerivedValues of a Character (ADR 0023): the formula of each derived field of the sheet over
 * the Character's values and the Campaign's Tracker values. They are computed on every read, never
 * stored. A field the Character holds no value for reads as its default. A formula that fails
 * (division by zero, overflow) gives null, and so does every formula that reads a null.
 */
final class DerivedValues
{
    /**
     * @param array<string, mixed> $values        the Character's stored values by field key
     * @param array<string, int>   $trackerValues the Campaign's value of every Tracker by key
     *
     * @return array<string, ?int> one per derived field, in sheet field order
     */
    public static function of(SheetTemplate $sheet, array $values, array $trackerValues): array
    {
        return SheetValues::of($sheet, $values, $trackerValues)->derived();
    }
}
