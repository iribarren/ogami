<?php

declare(strict_types=1);

namespace App\Play\Application;

/**
 * A Tracker of the campaign's pinned release with the campaign's value: a counter (min..max) or a
 * clock (0..segments).
 */
final readonly class TrackerView
{
    /**
     * @param string                 $kind       "counter" or "clock"
     * @param ?int                   $segments   clocks only
     * @param list<TrackerLevelView> $levels     counters only, in band order
     * @param ?string                $levelLabel the label of the level the value falls in; null without levels
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $kind,
        public ?string $hint,
        public int $min,
        public int $max,
        public ?int $segments,
        public array $levels,
        public int $value,
        public ?string $levelLabel,
    ) {
    }
}
