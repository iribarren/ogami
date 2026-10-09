<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

/**
 * Changes the current scene's Scene Type in place.
 */
final readonly class SwitchSceneTypeEffect implements Effect
{
    public function __construct(
        public string $sceneType,
    ) {
    }
}
