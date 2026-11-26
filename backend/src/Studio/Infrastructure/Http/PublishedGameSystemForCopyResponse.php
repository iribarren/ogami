<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\PublishedGameSystemSummary;
use OpenApi\Attributes as OA;

/**
 * One published GameSystem a draft can be copied from: its latest release.
 */
#[OA\Schema(required: ['key', 'name', 'description', 'latestVersion', 'publishedAt'])]
final readonly class PublishedGameSystemForCopyResponse
{
    private function __construct(
        #[OA\Property(example: 'ironsworn')]
        public string $key,
        #[OA\Property(example: 'Ironsworn')]
        public string $name,
        #[OA\Property(nullable: true)]
        public ?string $description,
        #[OA\Property(description: 'The version of its latest release; every version from 1 to this one can be copied.', example: 3)]
        public int $latestVersion,
        #[OA\Property(description: 'When the latest release was published.', format: 'date-time')]
        public string $publishedAt,
    ) {
    }

    public static function fromSummary(PublishedGameSystemSummary $summary): self
    {
        return new self($summary->gameSystemKey, $summary->name, $summary->description, $summary->version, $summary->publishedAt->format(\DATE_ATOM));
    }
}
