<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * A gear entry of the draft's catalog (ADR 0045) as stored, with the defaults a release leaves out
 * filled in: one slot when none is stored.
 */
final readonly class SheetGearEntryView
{
    public function __construct(
        public string $key,
        public string $name,
        public int $cost,
        public int $slots,
        public ?string $category,
        public ?string $description,
    ) {
    }
}
