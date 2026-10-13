<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Sheet;

use App\Formula\Domain\DependencyOrder;
use App\Formula\Domain\InvalidFormula;

/**
 * The character sheet a GameSystem release defines (ADR 0022): its fields in layout order. A
 * release without fields has an empty template, and its Characters have a name and tags only.
 */
final readonly class SheetTemplate
{
    /** @var array<string, Field> */
    private array $fields;

    /** @var list<string> the keys of the derived fields, each after the derived fields it reads */
    private array $derivedOrder;

    /**
     * @param list<Field> $fields in definition order
     *
     * @throws \InvalidArgumentException when two fields share a key or derived fields read each other in a cycle
     */
    public function __construct(array $fields = [])
    {
        $byKey = [];
        foreach ($fields as $field) {
            if (isset($byKey[$field->key])) {
                throw new \InvalidArgumentException(\sprintf('Sheet field key "%s" is listed twice.', $field->key));
            }

            $byKey[$field->key] = $field;
        }

        $this->fields = $byKey;

        $formulas = [];
        foreach ($byKey as $key => $field) {
            if ($field instanceof DerivedField) {
                $formulas[$key] = $field->formula;
            }
        }

        try {
            $this->derivedOrder = DependencyOrder::of($formulas);
        } catch (InvalidFormula $cycle) {
            throw new \InvalidArgumentException($cycle->getMessage(), 0, $cycle);
        }
    }

    /**
     * @return list<Field> in definition order
     */
    public function fields(): array
    {
        return array_values($this->fields);
    }

    public function field(string $key): ?Field
    {
        return $this->fields[$key] ?? null;
    }

    /**
     * The keys of the derived fields in the order to compute them: each after the derived fields it reads.
     *
     * @return list<string>
     */
    public function derivedOrder(): array
    {
        return $this->derivedOrder;
    }

    public function isEmpty(): bool
    {
        return [] === $this->fields;
    }
}
