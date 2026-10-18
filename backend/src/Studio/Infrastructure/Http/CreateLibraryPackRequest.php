<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/studio/library/packs`. Documents the contract only: the controller reads
 * the fields from the request.
 *
 * The optional description is nullable with no PHP default: a default makes Nelmio emit
 * `default: null`, which the typed client turns into a required field.
 */
#[OA\Schema(required: ['key', 'name'])]
final readonly class CreateLibraryPackRequest
{
    public function __construct(
        #[OA\Property(description: 'Names the pack and never changes: lower case letters, digits and hyphens. Not used by a GameSystem, another pack or any release (presets included).', example: 'fantasy-basics')]
        public string $key,
        #[OA\Property(description: 'The pack\'s name, at most 100 characters.', example: 'Fantasy basics')]
        public string $name,
        #[OA\Property(description: 'What the pack offers; absent or null for none.', example: 'Names, rumours and a tavern.', nullable: true)]
        public ?string $description,
    ) {
    }
}
