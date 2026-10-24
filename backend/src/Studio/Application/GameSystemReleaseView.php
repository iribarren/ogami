<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * A release as its GameSystem's owner reads it: the history line, the name it was published under and
 * what its sections hold (see SectionCounts and ReleaseOutline) and what it changed against the release
 * before it (see ReleaseDiff).
 *
 * @phpstan-import-type SectionChanges from ReleaseDiff
 */
final readonly class GameSystemReleaseView
{
    /**
     * @param array<string, int>                                                                                         $sectionCounts
     * @param array<string, list<array{key: string, name: string|null}>>                                                 $outline
     * @param array{previousVersion: int, previousSchemaVersion: int, sections: array<string, SectionChanges>|null}|null $changes       null for the first release
     */
    public function __construct(
        public GameSystemReleaseSummary $summary,
        public string $name,
        public array $sectionCounts,
        public array $outline,
        public ?array $changes,
    ) {
    }
}
