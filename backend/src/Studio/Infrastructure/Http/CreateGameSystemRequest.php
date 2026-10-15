<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/studio/game-systems`. Documents the contract only: the controller reads
 * the fields from the request.
 *
 * Optional fields are nullable with no PHP default: a default makes Nelmio emit `default: null`,
 * which the typed client turns into a required field.
 */
#[OA\Schema(required: ['key', 'name'])]
final readonly class CreateGameSystemRequest
{
    public function __construct(
        #[OA\Property(description: 'Names the GameSystem\'s release line and never changes: lower case letters, digits and hyphens. Not used by another GameSystem or by any release (presets included).', example: 'my-system')]
        public string $key,
        #[OA\Property(description: 'The GameSystem\'s name, at most 100 characters.', example: 'My system')]
        public string $name,
        #[OA\Property(description: 'On a copy, replaces the source release\'s description; absent or null keeps it. Blank drafts start without one when it is absent.', example: 'A game of my own.', nullable: true)]
        public ?string $description,
        #[OA\Property(ref: new Model(type: GameSystemSourceRequest::class), description: 'Copy a published release instead of starting blank; absent or null for a blank draft.', nullable: true)]
        public ?GameSystemSourceRequest $source,
    ) {
    }
}
