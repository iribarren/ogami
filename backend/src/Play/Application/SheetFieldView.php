<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\GameSystem\Sheet\DerivedField;
use App\Play\Domain\GameSystem\Sheet\Field;
use App\Play\Domain\GameSystem\Sheet\ListField;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\TextField;
use App\Play\Domain\GameSystem\Sheet\TrackField;

/**
 * One field of the pinned release's character sheet. `type` is "number", "text", "list", "track" or "derived";
 * the properties of the other types are null. A derived field holds its formula in the spelling the release gives it. Limits are resolved: a number's min and max default to
 * the contract's one million, a text's maxLength and a list's maxItems to their defaults.
 */
final readonly class SheetFieldView
{
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
    ) {
    }

    public static function of(Field $field): self
    {
        $common = [$field->key, $field->label, $field->hint, $field->group];

        return match (true) {
            $field instanceof NumberField => new self(...$common, type: 'number', min: $field->min ?? -NumberField::LIMIT, max: $field->max ?? NumberField::LIMIT, default: $field->initial()),
            $field instanceof TextField => new self(...$common, type: 'text', multiline: $field->multiline, maxLength: $field->limit()),
            $field instanceof ListField => new self(...$common, type: 'list', itemLabel: $field->itemLabel, maxItems: $field->limit()),
            $field instanceof TrackField => new self(...$common, type: 'track', max: $field->max),
            $field instanceof DerivedField => new self(...$common, type: 'derived', formula: $field->formula->toString()),
            default => throw new \LogicException(\sprintf('Unknown field type %s.', $field::class)),
        };
    }
}
