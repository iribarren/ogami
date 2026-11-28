<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * A published release with the card images it pinned: the Published Language of what Play shows on
 * the table. $content is the canonical release document, as in PublishedReleaseView.
 */
final readonly class PublishedReleaseContentView
{
    /**
     * @param array<string, mixed>         $content
     * @param list<PublishedCardImageView> $cardImages in slot order; none for a release published without images
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
