<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/studio/game-systems/{key}/sheet/fields` and `PUT …/sheet/fields/{field}`.
 * Documents the contract only: the controller reads the fields from the request, and the
 * publisher's rules validate the field, so its schema is free-form.
 */
#[OA\Schema(required: ['expectedVersion', 'field'])]
final readonly class SaveSheetFieldRequest
{
    /**
     * @param array<string, mixed> $field
     */
    public function __construct(
        #[OA\Property(description: 'The draft version the client read; another save since then is refused (409).', example: 3)]
        public int $expectedVersion,
        #[OA\Property(description: 'The field to create, or the whole replacement of the one in the path, as in a release document; its key is the new key on a rename.', type: 'object', example: ['key' => 'luck', 'label' => 'Luck', 'type' => 'number', 'min' => 0, 'max' => 10], additionalProperties: true)]
        public array $field,
    ) {
    }
}
