<?php

declare(strict_types=1);

namespace App\Play\Application;

final readonly class SceneView
{
    /**
     * @param string  $kind          "scene" or "hook"
     * @param ?string $sceneType     the Scene Type key; null for a hook Scene or a scene without one
     * @param ?string $sceneTypeName the Scene Type name in the pinned release
     * @param ?string $hook          a hook Scene's hook: "sessionOpening", "sessionClosing", "phaseOpening", "phaseClosing" or "worldTurn"
     */
    public function __construct(
        public int $number,
        public string $title,
        public \DateTimeImmutable $startedAt,
        public string $kind = 'scene',
        public ?string $sceneType = null,
        public ?string $sceneTypeName = null,
        public ?string $hook = null,
    ) {
    }
}
