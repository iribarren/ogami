<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/campaigns/{campaignId}/characters` and `PUT /api/campaigns/{campaignId}/characters/{characterId}`
 * (a full replacement: an omitted or null optional field takes its default, and so does a value
 * not given). Documents the contract only: the controller reads the body from the request.
 */
#[OA\Schema(required: ['name'])]
final readonly class CharacterRequest
{
    /**
     * @param ?list<string> $tags
     * @param ?list<string> $freeTags
     * @param ?array<mixed> $values
     */
    public function __construct(
        #[OA\Property(description: '1 to 100 characters once trimmed.', example: 'Vi')]
        public string $name,
        #[OA\Property(description: 'Keys of Tags of the campaign\'s pinned release, at most 10, each once; none when omitted or null.', type: 'array', items: new OA\Items(type: 'string'), example: ['ally'], nullable: true)]
        public ?array $tags,
        #[OA\Property(description: 'The player\'s own labels, at most 10 of 1 to 50 characters, unique ignoring case; none when omitted or null.', type: 'array', items: new OA\Items(type: 'string'), example: ['Fixer'], nullable: true)]
        public ?array $freeTags,
        #[OA\Property(description: 'The values of the pinned release\'s sheet fields: field key to value. A number field takes an integer, a text field a string, a list field an array of strings, a conditions field an array of the keys of its marked options (declared options only, each once; stored in the declared order of the options), an inventory field an array of stacks (a catalog stack {gear, quantity} naming gear the field accepts, at most once per field, or a free stack {name, quantity, slots}; quantity 1 to 9999, slots 0 to 100, used slots within the capacity, at most maxStacks stacks; ADR 0045) and a track field {current, max} (max is fixed by the field when it sets one, else required). A field not given takes its default (a number its default or 0, a text "", a list [], conditions [] or inventory [], a track full). An unknown key, a value of the wrong type or out of its bounds is refused.', type: 'object', example: ['role' => 'Solo', 'reflexes' => 7, 'hp' => ['current' => 30, 'max' => 40], 'gear' => ['Knife']], nullable: true, additionalProperties: new OA\AdditionalProperties(oneOf: [
            new OA\Schema(type: 'integer'),
            new OA\Schema(type: 'string'),
            new OA\Schema(type: 'array', items: new OA\Items(type: 'string')),
            new OA\Schema(type: 'array', items: new OA\Items(oneOf: [
                new OA\Schema(required: ['gear', 'quantity'], properties: [new OA\Property(property: 'gear', type: 'string'), new OA\Property(property: 'quantity', type: 'integer')], type: 'object'),
                new OA\Schema(required: ['name', 'quantity', 'slots'], properties: [new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'quantity', type: 'integer'), new OA\Property(property: 'slots', type: 'integer')], type: 'object'),
            ])),
            new OA\Schema(required: ['current', 'max'], properties: [new OA\Property(property: 'current', type: 'integer'), new OA\Property(property: 'max', type: 'integer')], type: 'object'),
        ]))]
        public ?array $values,
        #[OA\Property(description: 'How to create the Character when adding it (ADR 0040): "roll" has the server roll the sheet\'s dice defaults, "points" spends the sheet\'s point budget (a value for each budget field). Required when the sheet\'s budget covers a field with a dice default; refused when the sheet cannot create that way. Ignored when editing.', nullable: true, enum: ['roll', 'points'])]
        public ?string $method,
    ) {
    }
}
