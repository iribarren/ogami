<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\GameSystem\SceneType;

/**
 * A Scene Type of a release, as the player picks one for a scene, with its oracle shortcuts.
 */
final readonly class SceneTypeSummaryView
{
    /**
     * @param list<string> $oracles oracle keys, in shortcut order
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $purpose,
        public array $oracles,
    ) {
    }

    public static function of(SceneType $sceneType): self
    {
        return new self($sceneType->key, $sceneType->name, $sceneType->purpose, $sceneType->oracles);
    }
}
