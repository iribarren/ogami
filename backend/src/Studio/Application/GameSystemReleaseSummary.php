<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Studio\Domain\Release\GameSystemRelease;

/**
 * One line of a GameSystem's release history. The content hash is the full sha256; a UI shortens it.
 */
final readonly class GameSystemReleaseSummary
{
    /**
     * @param list<array{section: string, key: string, pack: string}>|null $librarySources the library items the release's draft imported, without their hashes; null when it recorded none
     */
    public function __construct(
        public int $version,
        public int $schemaVersion,
        public \DateTimeImmutable $publishedAt,
        public ?string $notes,
        public string $contentHash,
        public ?array $librarySources,
    ) {
    }

    public static function of(GameSystemRelease $release): self
    {
        return new self($release->version(), $release->schemaVersion(), $release->publishedAt(), $release->notes(), $release->contentHash(), self::sourcesOf($release));
    }

    /**
     * @return list<array{section: string, key: string, pack: string}>|null
     */
    private static function sourcesOf(GameSystemRelease $release): ?array
    {
        $sources = $release->librarySources();
        if (null === $sources) {
            return null;
        }

        return array_map(static fn (array $source): array => ['section' => $source['section'], 'key' => $source['key'], 'pack' => $source['pack']], $sources);
    }
}
