<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\GameSystem\Sheet\SheetLayout;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * How a release lays its character sheet out (ADR 0031): groups of fields placed in 1 or 2 columns.
 */
#[OA\Schema(required: ['columns', 'groups'])]
final readonly class SheetLayoutResponse
{
    /**
     * @param list<SheetGroupResponse> $groups
     */
    private function __construct(
        #[OA\Property(description: 'The columns of groups the sheet has.', example: 2, maximum: 2, minimum: 1)]
        public int $columns,
        #[OA\Property(
            description: 'The groups in declared order. A field names its group; a group without fields renders nothing.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: SheetGroupResponse::class)),
        )]
        public array $groups,
    ) {
    }

    public static function fromLayout(?SheetLayout $layout): ?self
    {
        return $layout instanceof SheetLayout ? new self($layout->columns, array_map(SheetGroupResponse::fromGroup(...), $layout->groups)) : null;
    }
}
