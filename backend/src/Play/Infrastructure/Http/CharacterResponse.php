<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\CharacterView;
use OpenApi\Attributes as OA;

/**
 * A Character of a campaign.
 */
#[OA\Schema(required: ['id', 'name', 'tags', 'freeTags', 'values', 'derived', 'promotedFrom'])]
final readonly class CharacterResponse
{
    /**
     * @param list<string> $tags
     * @param list<string> $freeTags
     */
    private function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,
        #[OA\Property(example: 'Vi')]
        public string $name,
        #[OA\Property(description: 'The keys of the pinned release\'s Tags the Character carries.', type: 'array', items: new OA\Items(type: 'string'), example: ['ally'])]
        public array $tags,
        #[OA\Property(description: 'The player\'s own labels, as typed.', type: 'array', items: new OA\Items(type: 'string'), example: ['Fixer'])]
        public array $freeTags,
        #[OA\Property(description: 'One value per field of the pinned release\'s sheet, in field order, named by the field key: an integer (number), a string (text), an array of strings (list), an array of the marked option keys in declared order (conditions) or {current, max} (track). Empty when the release has no sheet.', type: 'object', example: ['role' => 'Solo', 'reflexes' => 7, 'hp' => ['current' => 30, 'max' => 40], 'gear' => ['Knife']], additionalProperties: new OA\AdditionalProperties(oneOf: [
            new OA\Schema(type: 'integer'),
            new OA\Schema(type: 'string'),
            new OA\Schema(type: 'array', items: new OA\Items(type: 'string')),
            new OA\Schema(required: ['current', 'max'], properties: [new OA\Property(property: 'current', type: 'integer'), new OA\Property(property: 'max', type: 'integer')], type: 'object'),
        ]))]
        public \stdClass $values,
        #[OA\Property(description: 'The DerivedValue of each derived field of the pinned release\'s sheet, named by the field key, in field order: an integer, or null when its formula cannot be evaluated (division by zero, overflow) or reads such a value. Computed from the Character\'s values and the Campaign\'s Tracker values as they are now. Empty when the sheet has no derived field.', type: 'object', example: ['defense' => 11, 'reach' => null], additionalProperties: new OA\AdditionalProperties(type: 'integer', nullable: true))]
        public \stdClass $derived,
        #[OA\Property(description: 'The id of the NPC this Character was promoted from; null when it was created as a Character.', format: 'uuid', nullable: true)]
        public ?string $promotedFrom,
    ) {
    }

    public static function fromView(CharacterView $view): self
    {
        return new self($view->id, $view->name, $view->tags, $view->freeTags, (object) $view->values, (object) $view->derived, $view->promotedFrom);
    }
}
