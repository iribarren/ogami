<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Sheet;

/**
 * The points a player spends on number fields when a Character is created (ADR 0040): at most
 * $total over the $fields, each inside its own bounds and the budget's creation-only $min and $max.
 */
final readonly class PointBudget
{
    /**
     * @param list<string> $fields the keys of the number fields the budget covers
     */
    public function __construct(
        public int $total,
        public array $fields,
        public ?string $label = null,
        public ?int $min = null,
        public ?int $max = null,
    ) {
        if ($total < 1) {
            throw new \InvalidArgumentException('A point budget needs a total of at least 1.');
        }

        if ([] === $fields || \count($fields) !== \count(array_unique($fields))) {
            throw new \InvalidArgumentException('A point budget covers at least one field, each once.');
        }

        if (null !== $min && null !== $max && $max < $min) {
            throw new \InvalidArgumentException(\sprintf('The point budget max (%d) is below its min (%d).', $max, $min));
        }
    }

    public function covers(string $key): bool
    {
        return \in_array($key, $this->fields, true);
    }

    /**
     * The lowest and highest value the field takes under this budget: its own bounds narrowed by the budget's.
     *
     * @return array{int, int}
     */
    public function boundsOf(NumberField $field): array
    {
        return [
            max($field->min ?? -NumberField::LIMIT, $this->min ?? -NumberField::LIMIT),
            min($field->max ?? NumberField::LIMIT, $this->max ?? NumberField::LIMIT),
        ];
    }
}
