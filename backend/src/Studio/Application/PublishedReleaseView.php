<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * A published GameSystem release as other contexts see it: the Published Language between Studio
 * and Play (docs/contracts/gamesystem-release.md). It holds no Studio Domain types.
 *
 * $content is the canonical release document of its schema version: plain arrays in schema key
 * order, absent optionals left out, integers as integers. "sheet" is an empty \stdClass, so
 * json_encode($content) gives back the exact canonical JSON (whose sha256 is the content hash);
 * every other JSON object is a string-keyed array and every JSON array a list.
 */
final readonly class PublishedReleaseView
{
    /**
     * @param array<string, mixed> $content
     */
    public function __construct(
        public string $releaseId,
        public string $gameSystemKey,
        public int $version,
        public int $schemaVersion,
        public \DateTimeImmutable $publishedAt,
        public array $content,
    ) {
    }
}
