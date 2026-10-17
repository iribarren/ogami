<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * A band of a check: the outcome of a total up to a bound. Documents the contract only.
 *
 * Optional fields are nullable with no PHP default (see GameSystemOracleTableEntry).
 */
#[OA\Schema(
    description: 'Every band but the last has an "upTo"; literal bounds strictly increase. The first band whose bound is at least the total is the outcome, the last band catches the rest.',
    required: ['key', 'label'],
)]
final readonly class GameSystemCheckBand
{
    public function __construct(
        #[OA\Property(description: 'Unique among the check\'s bands; a check step\'s outcome names it.', example: 'success')]
        public string $key,
        #[OA\Property(example: 'Success')]
        public string $label,
        #[OA\Property(
            description: 'The inclusive upper bound of the band: a number, a tracker\'s value or a formula; absent on the last band.',
            oneOf: [
                new OA\Schema(type: 'integer', example: 7),
                new OA\Schema(required: ['tracker'], properties: [new OA\Property(property: 'tracker', type: 'string', example: 'alarm')], type: 'object'),
                new OA\Schema(required: ['formula'], properties: [new OA\Property(property: 'formula', type: 'string', example: '{check:difficulty}+4')], type: 'object'),
            ],
        )]
        public mixed $upTo,
    ) {
    }
}
