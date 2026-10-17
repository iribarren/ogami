<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * One group of the body of `PUT /api/studio/game-systems/{key}/sheet/layout`.
 * Documents the contract only: the controller passes the group on as sent.
 */
#[OA\Schema(required: ['name'])]
final readonly class SheetLayoutGroupRequest
{
    public function __construct(
        #[OA\Property(description: 'The group\'s name, 1 to 60 characters, unique in the layout; the one a field\'s "group" refers to.', example: 'Stats')]
        public string $name,
        #[OA\Property(description: 'The current name of the group this one renames; every field of it follows. Without it, the group is the one with this name, or a new one. Never stored.', example: 'Attributes', nullable: true)]
        public ?string $previousName,
        #[OA\Property(description: 'How many columns of fields the group has (1 to 4); 1 when left out.', example: 2, nullable: true)]
        public ?int $columns,
        #[OA\Property(description: 'How many of the sheet\'s columns the group spans (1 or 2, at most the sheet\'s columns); 1 when left out.', example: 1, nullable: true)]
        public ?int $span,
    ) {
    }
}
