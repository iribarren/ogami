<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

/**
 * Picks NPCs or Threads of the Campaign that carry all the listed Tags: drawn at random or chosen
 * by the player, between "min" and "max" of them. Continues by the Outcome "found", or "none"
 * when too few items match; a missing branch continues like a step without one.
 */
final readonly class PickStep extends Step
{
    public const int MAX_COUNT = 10;

    /**
     * @param list<Effect> $effects
     * @param list<string> $tags    Tag keys of the release; empty matches any item
     *
     * @throws \InvalidArgumentException unless 1 <= min <= max <= MAX_COUNT
     */
    public function __construct(
        string $key, string $title, ?string $prompt, ?string $tip, bool $mandatory, ?string $next, array $effects,
        public ItemKind $of,
        public array $tags,
        public PickMode $mode,
        public int $min = 1,
        public int $max = 1,
        public ?Outcome $found = null,
        public ?Outcome $none = null,
    ) {
        if ($min < 1 || $max < $min || $max > self::MAX_COUNT) {
            throw new \InvalidArgumentException(\sprintf('A pick step takes 1 <= min <= max <= %d items, got %d to %d.', self::MAX_COUNT, $min, $max));
        }

        parent::__construct($key, $title, $prompt, $tip, $mandatory, $next, $effects);
    }
}
