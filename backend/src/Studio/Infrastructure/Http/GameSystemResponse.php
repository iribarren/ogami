<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\GameSystemView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * A GameSystem draft or library pack as its owner sees it: its metadata and what its sections hold.
 */
#[OA\Schema(required: ['key', 'kind', 'name', 'description', 'credits', 'theme', 'schemaVersion', 'sectionCounts', 'latestReleaseVersion', 'hasUnpublishedChanges', 'libraryChanges', 'createdAt', 'updatedAt'])]
final readonly class GameSystemResponse
{
    /**
     * @param array<string, int> $sectionCounts
     */
    private function __construct(
        #[OA\Property(example: 'my-system')]
        public string $key,
        #[OA\Property(description: 'A GameSystem publishes releases; a library pack holds generic items that drafts import and is never published.', example: 'game-system', enum: ['game-system', 'library-pack'])]
        public string $kind,
        #[OA\Property(example: 'My system')]
        public string $name,
        #[OA\Property(example: 'A game of my own.', nullable: true)]
        public ?string $description,
        #[OA\Property(ref: new Model(type: GameSystemCreditsResponse::class), nullable: true)]
        public ?GameSystemCreditsResponse $credits,
        #[OA\Property(description: 'One of the release contract\'s themes; null for none.', example: 'noir', nullable: true)]
        public ?string $theme,
        #[OA\Property(description: 'The release contract\'s schema version of the draft; 1 has no credits or theme.', example: 2)]
        public int $schemaVersion,
        #[OA\Property(
            description: 'How many items each section holds, by section name: oracleTables, likelihoodOracles, trackers, factSlots, tags, sceneTypes, flows, sheetFields and checks (schema version 1: oracles and flowSteps).',
            type: 'object',
            example: ['oracleTables' => 2, 'flows' => 1],
            additionalProperties: new OA\AdditionalProperties(type: 'integer'),
        )]
        public array $sectionCounts,
        #[OA\Property(description: 'The version of its latest release; null when it was never published.', example: 3, nullable: true)]
        public ?int $latestReleaseVersion,
        #[OA\Property(description: 'True when it has no release, the draft is invalid, or the draft differs from the latest release.')]
        public bool $hasUnpublishedChanges,
        #[OA\Property(description: 'How many of the draft\'s library imports changed or went away in their library pack; 0 for a library pack and a draft without imports.', example: 1)]
        public int $libraryChanges,
        #[OA\Property(format: 'date-time')]
        public string $createdAt,
        #[OA\Property(format: 'date-time')]
        public string $updatedAt,
    ) {
    }

    public static function fromView(GameSystemView $view): self
    {
        return new self(
            $view->key,
            $view->kind->value,
            $view->name,
            $view->description,
            GameSystemCreditsResponse::fromArray($view->credits),
            $view->theme,
            $view->schemaVersion,
            $view->sectionCounts,
            $view->latestReleaseVersion,
            $view->hasUnpublishedChanges,
            $view->libraryChanges,
            $view->createdAt->format(\DATE_ATOM),
            $view->updatedAt->format(\DATE_ATOM),
        );
    }
}
