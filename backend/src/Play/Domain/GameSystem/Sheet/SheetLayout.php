<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Sheet;

/**
 * How a release lays its sheet out (ADR 0031): the groups in declared order, in 1 or 2 columns of
 * groups. A sheet without declared groups has no layout, and its consecutive fields of the same
 * group render together.
 */
final readonly class SheetLayout
{
    /**
     * @param list<SheetGroup> $groups in declared order
     */
    public function __construct(
        public int $columns,
        public array $groups,
    ) {
    }
}
