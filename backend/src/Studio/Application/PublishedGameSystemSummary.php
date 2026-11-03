<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * The latest published release of one GameSystem, as other contexts list it. Part of the Published
 * Language; it holds no Studio Domain types.
 *
 * $release is the same release as other contexts read it, so a context listing GameSystems needs no
 * second query per GameSystem.
 */
final readonly class PublishedGameSystemSummary
{
    public function __construct(
        public string $gameSystemKey,
        public string $name,
        public ?string $description,
        public int $version,
        public \DateTimeImmutable $publishedAt,
        public PublishedReleaseView $release,
    ) {
    }
}
