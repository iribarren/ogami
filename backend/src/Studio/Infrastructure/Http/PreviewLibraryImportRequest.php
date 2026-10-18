<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/studio/game-systems/{key}/library-imports/preview`. Documents the contract
 * only: the controller reads the fields from the request.
 */
#[OA\Schema(required: ['pack', 'items'])]
final readonly class PreviewLibraryImportRequest
{
    /**
     * @param list<LibraryItemRefRequest> $items
     */
    public function __construct(
        #[OA\Property(description: 'The key of the library pack to copy from.', example: 'fantasy-basics')]
        public string $pack,
        #[OA\Property(description: 'The items to import, at least one.', type: 'array', items: new OA\Items(ref: new Model(type: LibraryItemRefRequest::class)))]
        public array $items,
    ) {
    }
}
