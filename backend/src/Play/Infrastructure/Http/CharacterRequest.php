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
        #[OA\Property(description: 'The values of the pinned release\'s sheet fields: field key to value. A number field takes an integer, a text field a string, a list field an array of strings and a track field {current, max} (max is fixed by the field when it sets one, else required). A field not given takes its default (a number its default or 0, a text "", a list [], a track full). An unknown key, a value of the wrong type or out of its bounds is refused.', type: 'object', example: ['role' => 'Solo', 'reflexes' => 7, 'hp' => ['current' => 30, 'max' => 40], 'gear' => ['Knife']], nullable: true, additionalProperties: new OA\AdditionalProperties(oneOf: [
            new OA\Schema(type: 'integer'),
            new OA\Schema(type: 'string'),
            new OA\Schema(type: 'array', items: new OA\Items(type: 'string')),
            new OA\Schema(required: ['current', 'max'], properties: [new OA\Property(property: 'current', type: 'integer'), new OA\Property(property: 'max', type: 'integer')], type: 'object'),
        ]))]
        public ?array $values,
    ) {
    }
}
