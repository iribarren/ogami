<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\LibraryImportPreview;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * What an import would do to the draft, which the preview leaves as it is.
 */
#[OA\Schema(required: ['imports', 'overrides', 'conflicts', 'error'])]
final readonly class LibraryImportPreviewResponse
{
    /**
     * @param list<LibraryItemRefResponse> $imports
     * @param list<LibraryItemRefResponse> $overrides
     * @param list<LibraryItemRefResponse> $conflicts
     */
    private function __construct(
        #[OA\Property(description: 'The items the import copies: the requested ones and what they need, in pack order.', type: 'array', items: new OA\Items(ref: new Model(type: LibraryItemRefResponse::class)))]
        public array $imports,
        #[OA\Property(description: 'The needed items the draft already has and keeps, in pack order.', type: 'array', items: new OA\Items(ref: new Model(type: LibraryItemRefResponse::class)))]
        public array $overrides,
        #[OA\Property(description: 'The requested items the draft already has; any of them refuses the import (409).', type: 'array', items: new OA\Items(ref: new Model(type: LibraryItemRefResponse::class)))]
        public array $conflicts,
        #[OA\Property(ref: new Model(type: LibraryImportPreviewErrorResponse::class), description: 'When nothing conflicts, the item rule the result would break (the import would be refused with 422); otherwise null.', nullable: true)]
        public ?LibraryImportPreviewErrorResponse $error,
    ) {
    }

    public static function fromPreview(LibraryImportPreview $preview): self
    {
        return new self(
            self::refs($preview->imports),
            self::refs($preview->overrides),
            self::refs($preview->conflicts),
            null === $preview->error ? null : new LibraryImportPreviewErrorResponse($preview->error['path'], $preview->error['message']),
        );
    }

    /**
     * @param list<array{section: string, key: string}> $refs
     *
     * @return list<LibraryItemRefResponse>
     */
    private static function refs(array $refs): array
    {
        return array_map(LibraryItemRefResponse::fromArray(...), $refs);
    }
}
