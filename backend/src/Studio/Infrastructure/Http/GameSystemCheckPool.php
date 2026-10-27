<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * The dice pool a check rolls instead of dice (ADR 0041), with the critical pairs bonus and the
 * Hunger dice of ADR 0044. Documents the contract only.
 *
 * Optional fields are nullable with no PHP default (see GameSystemOracleTableEntry).
 */
#[OA\Schema(
    description: 'The pool rolls count (plus the situational modifier, 1 to 100) dice of `sides` sides; each die showing at least `successAt` is a success, and the total is the number of successes.',
    required: ['count', 'sides', 'successAt'],
)]
final readonly class GameSystemCheckPool
{
    /**
     * @param array{bonus: int}|null                        $criticals
     * @param array{count: int|array{formula: string}}|null $hunger
     */
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
        #[OA\Property(description: 'Critical pairs (ADR 0044): every pair of dice showing the top face adds `bonus` successes. Absent: no bonus.', required: ['bonus'], properties: [new OA\Property(property: 'bonus', description: '1 to 10.', type: 'integer', example: 2)], type: 'object', nullable: true)]
        public ?array $criticals,
        #[OA\Property(
            description: 'Hunger dice (ADR 0044): that many dice of the pool, in a second colour, are never re-rolled by a push. Absent: none.',
            required: ['count'],
            properties: [
                new OA\Property(
                    property: 'count',
                    description: 'An integer from 0 to 100, or a formula over the sheet read as a modifier; clamped to the size of the pool.',
                    oneOf: [
                        new OA\Schema(type: 'integer', example: 2),
                        new OA\Schema(required: ['formula'], properties: [new OA\Property(property: 'formula', type: 'string', example: '{hunger}')], type: 'object'),
                    ],
                ),
            ],
            type: 'object',
            nullable: true,
        )]
        public ?array $hunger,
    ) {
    }
}
