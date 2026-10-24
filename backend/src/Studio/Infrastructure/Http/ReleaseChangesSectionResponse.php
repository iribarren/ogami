<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * What one section of a release changed against the previous release. An item is in at most one of
 * added, removed and changed.
 */
#[OA\Schema(required: ['added', 'removed', 'changed', 'reordered'])]
final readonly class ReleaseChangesSectionResponse
{
    /**
     * @param list<ReleaseOutlineItemResponse> $added
     * @param list<ReleaseOutlineItemResponse> $removed
     * @param list<ReleaseChangesItemResponse> $changed
     */
    private function __construct(
        #[OA\Property(description: 'The items whose key the previous release lacks, in this release\'s content order.', type: 'array', items: new OA\Items(ref: new Model(type: ReleaseOutlineItemResponse::class)))]
        public array $added,
        #[OA\Property(description: 'The items of the previous release whose key this release lacks, in the previous release\'s content order.', type: 'array', items: new OA\Items(ref: new Model(type: ReleaseOutlineItemResponse::class)))]
        public array $removed,
        #[OA\Property(description: 'The items in both releases whose content differs, in this release\'s content order. Moving an item is not a change.', type: 'array', items: new OA\Items(ref: new Model(type: ReleaseChangesItemResponse::class)))]
        public array $changed,
        #[OA\Property(description: 'True when the items present in both releases are in another relative order.', example: false)]
        public bool $reordered,
    ) {
    }

    /**
     * @param array{added: list<array{key: string, name: string|null}>, removed: list<array{key: string, name: string|null}>, changed: list<array{key: string, name: string|null, previousName: string|null, fields: list<string>}>, reordered: bool} $section
     */
    public static function fromSection(array $section): self
    {
        return new self(
            array_map(ReleaseOutlineItemResponse::fromItem(...), $section['added']),
            array_map(ReleaseOutlineItemResponse::fromItem(...), $section['removed']),
            array_map(ReleaseChangesItemResponse::fromItem(...), $section['changed']),
            $section['reordered'],
        );
    }
}
