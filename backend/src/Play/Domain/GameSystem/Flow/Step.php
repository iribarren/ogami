<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

/**
 * A step of a Scene Type part or a phase hook, with the fields every kind shares. "effects" apply
 * when the step completes, before its Outcome's effects.
 */
abstract readonly class Step
{
    /**
     * @param list<Effect> $effects
     */
    public function __construct(
        public string $key,
        public string $title,
        public ?string $prompt,
        public ?string $tip,
        public bool $mandatory,
        public ?string $next,
        public array $effects,
    ) {
    }
}
