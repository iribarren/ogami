<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\GameSystemSummary;
use OpenApi\Attributes as OA;

/**
 * One GameSystem in the game manager's list.
 */
#[OA\Schema(required: ['key', 'name', 'schemaVersion', 'latestReleaseVersion', 'hasUnpublishedChanges', 'updatedAt'])]
final readonly class GameSystemSummaryResponse
{
    private function __construct(
        #[OA\Property(example: 'my-system')]
        public string $key,
        #[OA\Property(example: 'My system')]
        public string $name,
        #[OA\Property(description: 'The release contract\'s schema version of the draft.', example: 2)]
        public int $schemaVersion,
        #[OA\Property(description: 'The version of its latest release; null when it was never published.', example: 3, nullable: true)]
        public ?int $latestReleaseVersion,
        #[OA\Property(description: 'True when it has no release, the draft is invalid, or the draft differs from the latest release.')]
        public bool $hasUnpublishedChanges,
        #[OA\Property(format: 'date-time')]
        public string $updatedAt,
    ) {
    }

    public static function fromSummary(GameSystemSummary $summary): self
    {
        return new self($summary->key, $summary->name, $summary->schemaVersion, $summary->latestReleaseVersion, $summary->hasUnpublishedChanges, $summary->updatedAt->format(\DATE_ATOM));
    }
}
