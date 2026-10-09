<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

/**
 * Renames the current scene; the title may hold placeholders.
 */
final readonly class SceneTitleEffect implements Effect
{
    public function __construct(
        public string $title,
    ) {
    }
}
