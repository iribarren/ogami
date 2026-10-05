<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem;

/**
 * One step of a GameSystem's guided narrative flow, as Play runs it.
 */
final readonly class FlowStep
{
    public function __construct(
        private string $key,
        private string $title,
        private ?string $prompt,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function prompt(): ?string
    {
        return $this->prompt;
    }
}
