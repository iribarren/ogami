<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem;

/**
 * The credits of a GameSystem release (ADR 0021): the notice Play shows, such as a fan preset's
 * publisher disclaimer, with the name and link of the license it is provided under.
 */
final readonly class Credits
{
    public function __construct(
        public string $notice,
        public ?string $license = null,
        public ?string $url = null,
    ) {
    }
}
