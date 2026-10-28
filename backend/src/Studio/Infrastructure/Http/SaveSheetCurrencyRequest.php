<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of `PUT /api/studio/game-systems/{key}/sheet/currency`.
 * Documents the contract only: the controller reads the fields from the request, and the
 * publisher's rules validate the values.
 */
#[OA\Schema(required: ['expectedVersion', 'field'])]
final readonly class SaveSheetCurrencyRequest
{
    public function __construct(
        #[OA\Property(description: 'The draft version the client read; another save since then is refused (409).', example: 3)]
        public int $expectedVersion,
        #[OA\Property(description: 'The key of the number field that holds the money; never a derived field.', example: 'eddies')]
        public string $field,
        #[OA\Property(description: 'The unit amounts are shown with (1 to 10 characters); none when left out.', example: 'eb', nullable: true)]
        public ?string $unit,
    ) {
    }
}
