<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * A release as its GameSystem's owner reads it: the history line, the name it was published under and
 * what its sections hold (see SectionCounts and ReleaseOutline).
 */
final readonly class GameSystemReleaseView
{
    /**
     * @param array<string, int>                                         $sectionCounts
     * @param array<string, list<array{key: string, name: string|null}>> $outline
     */
    public function __construct(
        public GameSystemReleaseSummary $summary,
        public string $name,
        public array $sectionCounts,
        public array $outline,
    ) {
    }
}
