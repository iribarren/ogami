<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * A release as its GameSystem's owner reads it: the history line, the name it was published under and
 * what its sections hold (see SectionCounts).
 */
final readonly class GameSystemReleaseView
{
    /**
     * @param array<string, int> $sectionCounts
     */
    public function __construct(
        public GameSystemReleaseSummary $summary,
        public string $name,
        public array $sectionCounts,
    ) {
    }
}
