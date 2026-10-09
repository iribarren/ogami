<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

/**
 * Forces the Scene Type of the next scene pick.
 */
final readonly class NextSceneEffect implements Effect
{
    public function __construct(
        public string $sceneType,
    ) {
    }
}
