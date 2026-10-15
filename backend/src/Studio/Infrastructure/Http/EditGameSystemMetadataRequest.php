<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * JSON body of `PUT /api/studio/game-systems/{key}/metadata`: the metadata as a whole, so what the
 * body leaves out is cleared. Documents the contract only: the controller reads the fields from
 * the request.
 */
#[OA\Schema(required: ['name'])]
final readonly class EditGameSystemMetadataRequest
{
    public function __construct(
        #[OA\Property(description: 'The GameSystem\'s name, at most 100 characters.', example: 'My system')]
        public string $name,
        #[OA\Property(description: 'Absent or null for none.', example: 'A game of my own.', nullable: true)]
        public ?string $description,
        #[OA\Property(ref: new Model(type: GameSystemCreditsRequest::class), description: 'Absent or null for none. Refused (422) on a schema version 1 draft.', nullable: true)]
        public ?GameSystemCreditsRequest $credits,
        #[OA\Property(description: 'One of the release contract\'s themes; absent or null for none. Refused (422) on a schema version 1 draft.', example: 'noir', nullable: true)]
        public ?string $theme,
    ) {
    }
}
