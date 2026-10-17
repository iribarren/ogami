<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * JSON body of `PUT /api/studio/game-systems/{key}/sheet/layout`.
 * Documents the contract only: the controller reads the fields from the request, and the
 * publisher's rules validate the values.
 */
#[OA\Schema(required: ['expectedVersion', 'groups'])]
final readonly class SaveSheetLayoutRequest
{
    /**
     * @param list<SheetLayoutGroupRequest> $groups
     */
    public function __construct(
        #[OA\Property(description: 'The draft version the client read; another save since then is refused (409).', example: 3)]
        public int $expectedVersion,
        #[OA\Property(description: 'The sheet\'s columns of groups (1 or 2); 1 when left out.', example: 2, nullable: true)]
        public ?int $columns,
        #[OA\Property(description: 'The whole new list of groups, in order: at least one, at most 20. A group of the current layout that is not listed is dropped, which is refused while it holds fields.', type: 'array', items: new OA\Items(ref: new Model(type: SheetLayoutGroupRequest::class)), minItems: 1)]
        public array $groups,
    ) {
    }
}
