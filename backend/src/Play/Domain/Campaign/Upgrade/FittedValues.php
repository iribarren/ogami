<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Upgrade;

/**
 * A Character's values fitted to the target sheet, and the changes that took.
 */
final readonly class FittedValues
{
    /**
     * @param array<string, int|string|list<string>|array{current: int, max: int}> $values  one per stored field of the target sheet, in sheet order
     * @param list<FieldChange>                                                    $changes
     */
    public function __construct(
        public array $values,
        public array $changes,
    ) {
    }
}
