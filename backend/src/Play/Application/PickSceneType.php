<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Picks one of the Scene Types the scene pick of the guided campaign's FlowRun offers; its scene
 * starts.
 */
final readonly class PickSceneType implements Command
{
    public function __construct(
        public string $campaignId,
        public string $userId,
        public string $sceneTypeKey,
    ) {
    }
}
