<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Upgrade;

/**
 * One change to a stored Character value. Before is null for an added value, after for a removed one.
 */
final readonly class FieldChange
{
    /**
     * @param int|string|list<string>|array{current: int, max: int}|null $before
     * @param int|string|list<string>|array{current: int, max: int}|null $after
     */
    public function __construct(
        public string $fieldKey,
        public string $fieldName,
        public FieldChangeKind $kind,
        public int|string|array|null $before,
        public int|string|array|null $after,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['fieldKey' => $this->fieldKey, 'fieldName' => $this->fieldName, 'kind' => $this->kind->value, 'before' => $this->before, 'after' => $this->after];
    }
}
