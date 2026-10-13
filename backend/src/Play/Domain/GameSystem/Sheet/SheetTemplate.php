<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Sheet;

/**
 * The character sheet a GameSystem release defines (ADR 0022): its fields in layout order. A
 * release without fields has an empty template, and its Characters have a name and tags only.
 */
final readonly class SheetTemplate
{
    /** @var array<string, Field> */
    private array $fields;

    /**
     * @param list<Field> $fields in definition order
     *
     * @throws \InvalidArgumentException when two fields share a key
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

    public function isEmpty(): bool
    {
        return [] === $this->fields;
    }
}
