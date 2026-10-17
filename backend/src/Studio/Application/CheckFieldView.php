<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * A sheet field a check formula may read as {key}: a number, a track ({key.max} too) or a derived
 * value. $max is a track's max, null when it declares none or the field is not a track.
 */
final readonly class CheckFieldView
{
    public function __construct(
        public string $key,
        public string $label,
        public string $type,
        public ?int $max,
    ) {
    }
}
