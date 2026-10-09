<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem;

use App\Play\Domain\GameSystem\Flow\Effect;

/**
 * What schema version 2 adds to an oracle table entry: its key, the Scene Type it offers and the
 * Effects that apply when a flow step rolls it.
 */
final readonly class TableEntryMetadata
{
    /**
     * @param list<Effect> $effects
     */
    public function __construct(
        public ?string $key,
        public ?string $sceneType,
        public array $effects,
    ) {
    }
}
