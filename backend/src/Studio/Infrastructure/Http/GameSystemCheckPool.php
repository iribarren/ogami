<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * The dice pool a check rolls instead of dice (ADR 0041). Documents the contract only.
 */
#[OA\Schema(
    description: 'The pool rolls count (plus the situational modifier, 1 to 100) dice of `sides` sides; each die showing at least `successAt` is a success, and the total is the number of successes.',
    required: ['count', 'sides', 'successAt'],
)]
final readonly class GameSystemCheckPool
{
    public function __construct(
        #[OA\Property(
            description: 'The number of dice: an integer from 1 to 100, or a formula over the sheet (1 to 200 characters, read as a modifier).',
            oneOf: [
                new OA\Schema(type: 'integer', example: 4),
                new OA\Schema(required: ['formula'], properties: [new OA\Property(property: 'formula', type: 'string', example: '{agility}+{brawl}')], type: 'object'),
            ],
        )]
        public mixed $count,
        #[OA\Property(description: 'Faces of each die, 2 to 1,000.', example: 10)]
        public int $sides,
        #[OA\Property(description: 'A die showing this or more is a success, 1 to `sides`.', example: 6)]
        public int $successAt,
    ) {
    }
}
