<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\LibraryPackSummary;
use OpenApi\Attributes as OA;

/**
 * One library pack in the library, whoever wrote it.
 */
#[OA\Schema(required: ['key', 'name', 'description', 'mine', 'itemCounts', 'updatedAt'])]
final readonly class LibraryPackSummaryResponse
{
    /**
     * @param array<string, int> $itemCounts
     */
    private function __construct(
        #[OA\Property(example: 'fantasy-basics')]
        public string $key,
        #[OA\Property(example: 'Fantasy basics')]
        public string $name,
        #[OA\Property(example: 'Names, rumours and a tavern.', nullable: true)]
        public ?string $description,
        #[OA\Property(description: 'True when the signed-in game manager wrote the pack and may edit it.')]
        public bool $mine,
        #[OA\Property(
            description: 'How many items each section holds: oracleTables, likelihoodOracles, trackers, tags, factSlots and sceneTypes.',
            type: 'object',
            example: ['oracleTables' => 2, 'trackers' => 1],
            additionalProperties: new OA\AdditionalProperties(type: 'integer'),
        )]
        public array $itemCounts,
        #[OA\Property(format: 'date-time')]
        public string $updatedAt,
    ) {
    }

    public static function fromSummary(LibraryPackSummary $summary): self
    {
        return new self($summary->key, $summary->name, $summary->description, $summary->mine, $summary->itemCounts, $summary->updatedAt->format(\DATE_ATOM));
    }
}
