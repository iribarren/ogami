<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem;

use App\Play\Domain\GameSystem\Flow\StepList;

/**
 * A Scene Type of a GameSystem release: its guidance, its oracle shortcuts and its setup, play
 * and closing step lists.
 */
final readonly class SceneType
{
    /**
     * @param list<string> $oracles oracle keys, in shortcut order
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $purpose,
        public ?string $tips,
        public array $oracles,
        public StepList $setup,
        public StepList $play,
        public StepList $closing,
    ) {
    }
}
