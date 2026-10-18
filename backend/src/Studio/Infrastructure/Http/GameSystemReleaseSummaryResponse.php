<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\GameSystemReleaseSummary;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * One release in a GameSystem's history.
 */
#[OA\Schema(required: ['version', 'schemaVersion', 'publishedAt', 'notes', 'contentHash', 'librarySources'])]
final readonly class GameSystemReleaseSummaryResponse
{
    private function __construct(
        #[OA\Property(example: 3)]
        public int $version,
        #[OA\Property(description: 'The release contract\'s schema version of the release.', example: 2)]
        public int $schemaVersion,
        #[OA\Property(format: 'date-time')]
        public string $publishedAt,
        #[OA\Property(description: 'The release notes written when it was published from Studio; null for a release published from a file.', example: 'Rebalanced the oracles.', nullable: true)]
        public ?string $notes,
        #[OA\Property(description: 'The full sha256 of the canonical content; a UI shows a short form.', example: '11d93fc7adb574ab68471c10f7eea5b2c0d4f3a6b8e9d1c2a3b4c5d6e7f8a9b0')]
        public string $contentHash,
        /**
         * @var list<LibrarySourceResponse>|null
         */
        #[OA\Property(description: 'The library items the draft had imported when this release was published, for the history only (the release itself is self-contained); null when it recorded none, as for a release without imports.', type: 'array', items: new OA\Items(ref: new Model(type: LibrarySourceResponse::class)), nullable: true)]
        public ?array $librarySources,
    ) {
    }

    public static function fromSummary(GameSystemReleaseSummary $summary): self
    {
        return new self($summary->version, $summary->schemaVersion, $summary->publishedAt->format(\DATE_ATOM), $summary->notes, $summary->contentHash, null === $summary->librarySources ? null : array_map(LibrarySourceResponse::fromSource(...), $summary->librarySources));
    }
}
