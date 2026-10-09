<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

/**
 * A Flow of a GameSystem release: a guided way to play it, as ordered Phases, with the oracles and
 * trackers it shows.
 */
final readonly class Flow
{
    /**
     * @param list<string> $oracles  oracle keys, in panel order
     * @param list<string> $trackers tracker keys, in panel order
     * @param list<Phase>  $phases   in play order, unique keys, at least one
     */
    public function __construct(
        public string $key,
        public string $name,
        public ?string $description,
        public ?string $introduction,
        public bool $default,
        public FlowView $defaultView,
        public array $oracles,
        public array $trackers,
        public array $phases,
    ) {
    }

    public function phase(string $key): ?Phase
    {
        $index = $this->phaseIndex($key);

        return null === $index ? null : $this->phases[$index];
    }

    /**
     * The position of the phase with this key, from 0; null when the flow has none.
     */
    public function phaseIndex(string $key): ?int
    {
        foreach ($this->phases as $index => $phase) {
            if ($phase->key === $key) {
                return $index;
            }
        }

        return null;
    }

    public function phaseAt(int $index): ?Phase
    {
        return $this->phases[$index] ?? null;
    }
}
