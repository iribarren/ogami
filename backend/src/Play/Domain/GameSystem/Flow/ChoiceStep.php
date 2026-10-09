<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

/**
 * The player picks an option; a skipped suggested choice follows its "skip" option.
 */
final readonly class ChoiceStep extends Step
{
    /**
     * @param list<Effect>       $effects
     * @param list<ChoiceOption> $options
     */
    public function __construct(
        string $key, string $title, ?string $prompt, ?string $tip, bool $mandatory, ?string $next, array $effects,
        public array $options,
        public ?string $skip,
    ) {
        parent::__construct($key, $title, $prompt, $tip, $mandatory, $next, $effects);
    }

    public function option(string $key): ?ChoiceOption
    {
        foreach ($this->options as $option) {
            if ($option->key === $key) {
                return $option;
            }
        }

        return null;
    }
}
