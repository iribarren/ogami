<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

/**
 * The player writes an answer. It may list oracle tables as inspiration: suggestions the player can
 * roll for ideas, with no effects, that the step itself never rolls.
 */
final readonly class PromptStep extends Step
{
    /**
     * @param list<Effect> $effects
     * @param list<string> $inspiration the keys of the oracle tables offered, none when the release lists none
     */
    public function __construct(
        string $key, string $title, ?string $prompt, ?string $tip, bool $mandatory, ?string $next, array $effects,
        public array $inspiration = [],
    ) {
        parent::__construct($key, $title, $prompt, $tip, $mandatory, $next, $effects);
    }

    /**
     * Whether completing the step changes a Character's sheet: the player names the Character.
     */
    public function changesSheet(): bool
    {
        return array_any($this->effects, static fn (Effect $effect): bool => $effect instanceof SheetEffect);
    }
}
