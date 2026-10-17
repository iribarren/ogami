<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\GameSystem\Sheet\SheetGroup;
use OpenApi\Attributes as OA;

/**
 * A group of the sheet layout (ADR 0031): a box of fields with its own columns.
 */
#[OA\Schema(required: ['name', 'columns', 'span'])]
final readonly class SheetGroupResponse
{
    private function __construct(
        #[OA\Property(description: 'The name the fields of the group carry in their `group`.', example: 'Stats')]
        public string $name,
        #[OA\Property(description: 'The columns of fields inside the group.', example: 2, maximum: 4, minimum: 1)]
        public int $columns,
        #[OA\Property(description: 'How many columns of the sheet the group takes.', example: 1, maximum: 2, minimum: 1)]
        public int $span,
    ) {
    }

    public static function fromGroup(SheetGroup $group): self
    {
        return new self($group->name, $group->columns, $group->span);
    }
}
