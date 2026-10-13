<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\Characters\Character;
use App\Play\Domain\Campaign\Characters\DerivedValues;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;

/**
 * A Character of a campaign: release Tag keys, free tags, the values of the pinned release's sheet
 * (field key to value), its DerivedValues (derived field key to value, null when the formula cannot be
 * evaluated) and the id of the NPC it was promoted from, if any.
 */
final readonly class CharacterView
{
    /**
     * @param list<string>                                                         $tags     release Tag keys
     * @param list<string>                                                         $freeTags
     * @param array<string, int|string|list<string>|array{current: int, max: int}> $values   one per stored sheet field, in field order
     * @param array<string, ?int>                                                  $derived  one per derived field, in field order
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $tags,
        public array $freeTags,
        public array $values,
        public array $derived,
        public ?string $promotedFrom,
    ) {
    }

    /**
     * The values come in the order of the sheet's fields: the database keeps JSONB keys in its own
     * order, so a Character read back does not. The DerivedValues are computed from the Campaign's
     * Tracker values as they are now.
     *
     * @param array<string, int> $trackerValues the Campaign's value of every Tracker by key
     */
    public static function of(Character $character, SheetTemplate $sheet, array $trackerValues): self
    {
        $values = [];
        foreach ($sheet->fields() as $field) {
            if (\array_key_exists($field->key, $character->values())) {
                $values[$field->key] = $character->values()[$field->key];
            }
        }

        return new self($character->id()->toString(), $character->name(), $character->tags(), $character->freeTags(), $values, DerivedValues::of($sheet, $character->values(), $trackerValues), $character->promotedFrom()?->toString());
    }
}
