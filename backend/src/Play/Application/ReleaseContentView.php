<?php

declare(strict_types=1);

namespace App\Play\Application;

/**
 * The release a campaign is pinned to as it was published: its whole content document and the card
 * images it pinned, which the table draws its cards from (ADR 0054).
 */
final readonly class ReleaseContentView
{
    /**
     * @param array<string, mixed>       $content    the canonical release document (docs/contracts/gamesystem-release.md)
     * @param list<ReleaseCardImageView> $cardImages in slot order; none for a release published without images
     */
    public function __construct(
        public string $gameSystemKey,
        public int $version,
        public \DateTimeImmutable $publishedAt,
        public array $content,
        public array $cardImages,
    ) {
    }
}
