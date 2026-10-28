<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\GameSystemReleaseView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * One release of a GameSystem as its owner reads it.
 */
#[OA\Schema(required: ['version', 'schemaVersion', 'publishedAt', 'notes', 'contentHash', 'librarySources', 'name', 'sectionCounts', 'outline', 'changes'])]
final readonly class GameSystemReleaseResponse
{
    /**
     * @param list<LibrarySourceResponse>|null                $librarySources
     * @param array<string, int>                              $sectionCounts
     * @param array<string, list<ReleaseOutlineItemResponse>> $outline
     */
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
        #[OA\Property(description: 'The library items the draft had imported when this release was published, for the history only (the release itself is self-contained); null when it recorded none, as for a release without imports.', type: 'array', items: new OA\Items(ref: new Model(type: LibrarySourceResponse::class)), nullable: true)]
        public ?array $librarySources,
        #[OA\Property(description: 'The name the release was published under.', example: 'My system')]
        public string $name,
        #[OA\Property(
            description: 'How many items each section holds, by section name, as for a draft: oracleTables, likelihoodOracles, trackers, factSlots, tags, sceneTypes, flows, sheetFields and checks (schema version 1: oracles and flowSteps).',
            type: 'object',
            example: ['oracleTables' => 2, 'flows' => 1],
            additionalProperties: new OA\AdditionalProperties(type: 'integer'),
        )]
        public array $sectionCounts,
        #[OA\Property(
            description: 'The items of each section in content order, by the same section names as sectionCounts plus gear, the catalog (ADR 0045), which has no count; every other list is as long as its count.',
            type: 'object',
            additionalProperties: new OA\AdditionalProperties(type: 'array', items: new OA\Items(ref: new Model(type: ReleaseOutlineItemResponse::class))),
        )]
        public array $outline,
        #[OA\Property(ref: new Model(type: ReleaseChangesResponse::class), description: 'What the release changed against the release before it (version N-1); null for the first release, or when that one is missing.', nullable: true)]
        public ?ReleaseChangesResponse $changes,
    ) {
    }

    public static function fromView(GameSystemReleaseView $view): self
    {
        $summary = $view->summary;

        $librarySources = null === $summary->librarySources ? null : array_map(LibrarySourceResponse::fromSource(...), $summary->librarySources);
        $outline = array_map(static fn (array $items): array => array_map(ReleaseOutlineItemResponse::fromItem(...), $items), $view->outline);

        return new self($summary->version, $summary->schemaVersion, $summary->publishedAt->format(\DATE_ATOM), $summary->notes, $summary->contentHash, $librarySources, $view->name, $view->sectionCounts, $outline, null === $view->changes ? null : ReleaseChangesResponse::fromChanges($view->changes));
    }
}
