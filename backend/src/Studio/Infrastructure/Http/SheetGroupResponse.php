<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\SheetGroupView;
use OpenApi\Attributes as OA;

/**
 * One declared group of the sheet layout, with the defaults filled in.
 */
#[OA\Schema(required: ['name', 'columns', 'span'])]
final readonly class SheetGroupResponse
{
    private function __construct(
        #[OA\Property(description: 'The group\'s name, the one a field\'s "group" refers to.', example: 'Stats')]
        public string $name,
        #[OA\Property(description: 'How many columns of fields the group has (1 to 4), 1 when not set.', example: 2)]
        public int $columns,
        #[OA\Property(description: 'How many of the sheet\'s columns the group spans (1 or 2), 1 when not set.', example: 1)]
        public int $span,
    ) {
    }

    public static function fromView(SheetGroupView $view): self
    {
        return new self($view->name, $view->columns, $view->span);
    }
}
