<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\SheetFieldView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * One field of the character sheet of a campaign's pinned release. The properties of the other
 * types than `type` are null.
 */
#[OA\Schema(required: ['key', 'label', 'hint', 'group', 'type', 'min', 'max', 'default', 'multiline', 'maxLength', 'itemLabel', 'maxItems', 'formula', 'roll', 'startMax', 'span', 'options'])]
final readonly class SheetFieldResponse
{
    /**
     * @param ?list<ConditionOptionResponse> $options
     */
    private function __construct(
        #[OA\Property(description: 'The key Character values are stored under.', example: 'reflexes')]
        public string $key,
        #[OA\Property(example: 'Reflexes')]
        public string $label,
        #[OA\Property(description: 'Help shown with the field; null when there is none.', nullable: true)]
        public ?string $hint,
        #[OA\Property(description: 'Consecutive fields with the same group are laid out together; null when ungrouped.', example: 'Stats', nullable: true)]
        public ?string $group,
        #[OA\Property(description: 'A number is an integer, a text a string, a list an array of strings, a conditions field an array of the keys of its marked options and a track {current, max}. A derived field holds no value: it is computed from its formula and shown read-only.', example: 'number', enum: ['number', 'text', 'list', 'track', 'conditions', 'derived'])]
        public string $type,
        #[OA\Property(description: 'Number: the lowest value (-1000000 when the field sets none). Null for other types.', example: 1, nullable: true)]
        public ?int $min,
        #[OA\Property(description: 'Number: the highest value (1000000 when the field sets none). Track: the fixed max; null when each Character sets its own. Null for other types.', example: 10, nullable: true)]
        public ?int $max,
        #[OA\Property(description: 'Number: the value of a Character that has none. Null for other types.', example: 4, nullable: true)]
        public ?int $default,
        #[OA\Property(description: 'Text: whether it may span lines. Null for other types.', nullable: true)]
        public ?bool $multiline,
        #[OA\Property(description: 'Text: the most characters (200, or 2000 when multiline, unless the field sets one). Null for other types.', example: 200, nullable: true)]
        public ?int $maxLength,
        #[OA\Property(description: 'List: what one item is called, such as "Item". Null when none or for other types.', nullable: true)]
        public ?string $itemLabel,
        #[OA\Property(description: 'List: the most items (50 unless the field sets one). Null for other types.', example: 50, nullable: true)]
        public ?int $maxItems,
        #[OA\Property(description: 'Derived: the formula, as the release writes it ({str}, {hp.max}, {tracker:chaos}, + - * /, min, max). Null for other types.', example: '{reflexes} + 4', nullable: true)]
        public ?string $formula,
        #[OA\Property(description: 'Number: the dice expression the server rolls for this field when a Character is created (ADR 0040); the Character is sent no value for it unless the field is spent from the point budget. Null for other types and for a number without one.', example: '3d6*5', nullable: true)]
        public ?string $roll,
        #[OA\Property(description: 'Track: the formula its max and current start at when a Character is created (ADR 0040), read over the other values; the max then stays per Character. Null for other types and for a track without one.', example: '({con} + {siz}) / 10', nullable: true)]
        public ?string $startMax,
        #[OA\Property(description: 'How many columns of its group the field takes; 1 for a release without a layout.', example: 2, minimum: 1)]
        public int $span,
        #[OA\Property(
            description: 'Conditions: the options a Character can be marked with, in declared order; the value is the list of marked option keys, in this order, each once, and a formula reads their count. Null for other types.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: ConditionOptionResponse::class)),
            nullable: true,
        )]
        public ?array $options,
    ) {
    }

    public static function fromView(SheetFieldView $view): self
    {
        return new self($view->key, $view->label, $view->hint, $view->group, $view->type, $view->min, $view->max, $view->default, $view->multiline, $view->maxLength, $view->itemLabel, $view->maxItems, $view->formula, $view->roll, $view->startMax, $view->span, null === $view->options ? null : array_map(ConditionOptionResponse::fromView(...), $view->options));
    }
}
