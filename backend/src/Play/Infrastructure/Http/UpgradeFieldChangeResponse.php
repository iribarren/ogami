<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Campaign\Upgrade\FieldChange;
use OpenApi\Attributes as OA;

/**
 * How the upgrade changes one sheet field of a Character.
 */
#[OA\Schema(required: ['fieldKey', 'fieldName', 'kind', 'before', 'after'])]
final readonly class UpgradeFieldChangeResponse
{
    /**
     * @param int|string|list<string>|list<array<string, int|string>>|array{current: int, max: int}|null $before
     * @param int|string|list<string>|list<array<string, int|string>>|array{current: int, max: int}|null $after
     */
    private function __construct(
        #[OA\Property(example: 'reflexes')]
        public string $fieldKey,
        #[OA\Property(description: 'The field\'s label in the release that has it (the target release, else the one the campaign plays).', example: 'Reflexes')]
        public string $fieldName,
        #[OA\Property(description: '"added" (a new field starts at its initial value), "removed" (the field is gone or now derived), "clamped" (a number or track moved inside its new bounds), "trimmed" (a list cut to its new limit) "converted" (a list became an inventory or the other way round, or a stack whose gear is no longer accepted became a free stack: the value is kept as far as it fits, ADR 0045) or "reset" (a type change or a value that still does not fit goes back to the initial value).', enum: ['added', 'removed', 'clamped', 'trimmed', 'converted', 'reset'])]
        public string $kind,
        #[OA\Property(description: 'The stored value now: an integer, a string, an array of strings, an array of inventory stacks or {current, max}; null for an added field.', nullable: true, oneOf: [
            new OA\Schema(type: 'integer'),
            new OA\Schema(type: 'string'),
            new OA\Schema(type: 'array', items: new OA\Items(type: 'string')),
            new OA\Schema(type: 'array', items: new OA\Items(oneOf: [
                new OA\Schema(required: ['gear', 'quantity'], properties: [new OA\Property(property: 'gear', type: 'string'), new OA\Property(property: 'quantity', type: 'integer')], type: 'object'),
                new OA\Schema(required: ['name', 'quantity', 'slots'], properties: [new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'quantity', type: 'integer'), new OA\Property(property: 'slots', type: 'integer')], type: 'object'),
            ])),
            new OA\Schema(required: ['current', 'max'], properties: [new OA\Property(property: 'current', type: 'integer'), new OA\Property(property: 'max', type: 'integer')], type: 'object'),
        ])]
        public int|string|array|null $before,
        #[OA\Property(description: 'The value after the upgrade, of the same shapes as "before"; null for a removed field.', nullable: true, oneOf: [
            new OA\Schema(type: 'integer'),
            new OA\Schema(type: 'string'),
            new OA\Schema(type: 'array', items: new OA\Items(type: 'string')),
            new OA\Schema(type: 'array', items: new OA\Items(oneOf: [
                new OA\Schema(required: ['gear', 'quantity'], properties: [new OA\Property(property: 'gear', type: 'string'), new OA\Property(property: 'quantity', type: 'integer')], type: 'object'),
                new OA\Schema(required: ['name', 'quantity', 'slots'], properties: [new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'quantity', type: 'integer'), new OA\Property(property: 'slots', type: 'integer')], type: 'object'),
            ])),
            new OA\Schema(required: ['current', 'max'], properties: [new OA\Property(property: 'current', type: 'integer'), new OA\Property(property: 'max', type: 'integer')], type: 'object'),
        ])]
        public int|string|array|null $after,
    ) {
    }

    public static function fromChange(FieldChange $change): self
    {
        return new self($change->fieldKey, $change->fieldName, $change->kind->value, $change->before, $change->after);
    }
}
