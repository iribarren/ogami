<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem;

/**
 * One entry of the release's gear catalog (ADR 0045): what a stack of it costs per unit in the
 * currency and how many inventory slots one unit takes. The category is the exact label shops and
 * inventory fields match; the name is unique in the catalog, trimmed and ignoring case.
 */
final readonly class GearEntry
{
    public const int DEFAULT_SLOTS = 1;

    public const int MAX_SLOTS = 100;

    public function __construct(
        public string $key,
        public string $name,
        public int $cost,
        public int $slots = self::DEFAULT_SLOTS,
        public ?string $category = null,
        public ?string $description = null,
    ) {
    }
}
