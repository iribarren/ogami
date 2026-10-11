<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\GameSystem\Flow\Flow;

/**
 * A Flow of a release as a player chooses it: what it is, whether it is the release's default and
 * how it opens (`focus` or `journal`), with the oracles and Trackers it shows.
 */
final readonly class FlowSummaryView
{
    /**
     * @param list<string> $oracles  oracle keys, in panel order
     * @param list<string> $trackers Tracker keys, in panel order
     */
    public function __construct(
        public string $key,
        public string $name,
        public ?string $description,
        public ?string $introduction,
        public bool $default,
        public string $defaultView,
        public array $oracles,
        public array $trackers,
    ) {
    }

    public static function of(Flow $flow): self
    {
        return new self($flow->key, $flow->name, $flow->description, $flow->introduction, $flow->default, $flow->defaultView->value, $flow->oracles, $flow->trackers);
    }
}
