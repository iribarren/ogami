<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Studio\Domain\Image\CardImagePin;

/**
 * A release as published: its content document (the canonical form of the release contract) and the
 * card images it pinned, which is what printing reads.
 */
final readonly class GameSystemReleaseContentView
{
    /**
     * @param array<string, mixed> $content    canonical content, see ReleaseContent::toArray()
     * @param list<CardImagePin>   $cardImages in slot order; none for a release published without images
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
