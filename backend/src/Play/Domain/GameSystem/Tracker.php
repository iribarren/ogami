<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem;

/**
 * A Tracker of a GameSystem release: a counter (min..max, initial, optional levels) or a clock of
 * N segments, which runs 0..N and starts at 0.
 */
final readonly class Tracker
{
    /**
     * @param list<TrackerLevel> $levels counters only, in band order
     */
    private function __construct(
        public string $key,
        public string $name,
        public ?string $hint,
        public TrackerKind $kind,
        public int $min,
        public int $max,
        public int $initial,
        public array $levels,
    ) {
    }

    /**
     * @param list<TrackerLevel> $levels
     */
    public static function counter(string $key, string $name, ?string $hint, int $min, int $max, int $initial, array $levels = []): self
    {
        return new self($key, $name, $hint, TrackerKind::Counter, $min, $max, $initial, $levels);
    }

    public static function clock(string $key, string $name, ?string $hint, int $segments): self
    {
        return new self($key, $name, $hint, TrackerKind::Clock, 0, $segments, 0, []);
    }

    /**
     * The number of segments of a clock; null for a counter.
     */
    public function segments(): ?int
    {
        return TrackerKind::Clock === $this->kind ? $this->max : null;
    }

    /**
     * The value within the Tracker's range: min..max for a counter, 0..segments for a clock.
     */
    public function clamp(int $value): int
    {
        return max($this->min, min($this->max, $value));
    }

    /**
     * The level the value falls in: the first whose "upTo" it does not pass, or the last one,
     * which has no "upTo". Null when the Tracker has no levels.
     */
    public function levelAt(int $value): ?TrackerLevel
    {
        foreach ($this->levels as $level) {
            if (null === $level->upTo || $value <= $level->upTo) {
                return $level;
            }
        }

        return null;
    }
}
