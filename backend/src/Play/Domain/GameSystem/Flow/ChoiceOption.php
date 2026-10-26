<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

final readonly class ChoiceOption
{
    public function __construct(
        public string $key,
        public string $label,
        public Outcome $outcome,
    ) {
    }

    /**
     * Whether picking the option changes a Character's sheet: the player names the Character.
     */
    public function changesSheet(): bool
    {
        return $this->outcome->changesSheet();
    }
}
