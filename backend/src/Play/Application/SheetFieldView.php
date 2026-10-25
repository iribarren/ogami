<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\GameSystem\Sheet\ConditionOption;
use App\Play\Domain\GameSystem\Sheet\ConditionsField;
use App\Play\Domain\GameSystem\Sheet\DerivedField;
use App\Play\Domain\GameSystem\Sheet\Field;
use App\Play\Domain\GameSystem\Sheet\ListField;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\TextField;
use App\Play\Domain\GameSystem\Sheet\TrackField;

/**
 * One field of the pinned release's character sheet. `type` is "number", "text", "list", "track", "conditions" or "derived";
 * the properties of the other types are null. `span` is how many columns of its group the field takes (ADR 0031). A derived field holds its formula in the spelling the release gives it. A number's `roll` is the normalized dice expression the server rolls at creation, a track's `startMax` the formula it starts full at (ADR 0040). Limits are resolved: a number's min and max default to
 * the contract's one million, a text's maxLength and a list's maxItems to their defaults. A conditions field's `options` are its marks in declared order (ADR 0041); its value is the list of the marked option keys.
 */
final readonly class SheetFieldView
{
    /**
     * @param ?list<ConditionOptionView> $options
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $hint,
        public ?string $group,
        public string $type,
        public ?int $min = null,
        public ?int $max = null,
        public ?int $default = null,
        public ?bool $multiline = null,
        public ?int $maxLength = null,
        public ?string $itemLabel = null,
        public ?int $maxItems = null,
        public ?string $formula = null,
        public int $span = 1,
        public ?string $roll = null,
        public ?string $startMax = null,
        public ?array $options = null,
    ) {
    }

    public static function of(Field $field): self
    {
        $common = ['key' => $field->key, 'label' => $field->label, 'hint' => $field->hint, 'group' => $field->group, 'span' => $field->span];

        return match (true) {
            $field instanceof NumberField => new self(...$common, type: 'number', min: $field->min ?? -NumberField::LIMIT, max: $field->max ?? NumberField::LIMIT, default: $field->initial(), roll: $field->roll?->notation()),
            $field instanceof TextField => new self(...$common, type: 'text', multiline: $field->multiline, maxLength: $field->limit()),
            $field instanceof ListField => new self(...$common, type: 'list', itemLabel: $field->itemLabel, maxItems: $field->limit()),
            $field instanceof TrackField => new self(...$common, type: 'track', max: $field->max, startMax: $field->startMax?->toString()),
            $field instanceof ConditionsField => new self(...$common, type: 'conditions', options: array_map(static fn (ConditionOption $option): ConditionOptionView => new ConditionOptionView($option->key, $option->label), $field->options)),
            $field instanceof DerivedField => new self(...$common, type: 'derived', formula: $field->formula->toString()),
            default => throw new \LogicException(\sprintf('Unknown field type %s.', $field::class)),
        };
    }
}
