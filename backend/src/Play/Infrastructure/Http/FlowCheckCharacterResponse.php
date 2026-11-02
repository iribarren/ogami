<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * A Character who can roll a check step, with the pool the roll would use now.
 */
#[OA\Schema(required: ['id', 'name', 'poolSize'])]
final readonly class FlowCheckCharacterResponse
{
    private function __construct(
        #[OA\Property(description: 'The id of the Character.', example: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b')]
        public string $id,
        #[OA\Property(example: 'Ada')]
        public string $name,
        #[OA\Property(description: 'The dice the Character would roll for the step\'s pool check before any situational modifier, worked out as the roll does (kept in 1..100); null when the step has a check set (the pool depends on the check picked), the check rolls no pool, or the pool cannot be evaluated now.', example: 6, nullable: true)]
        public ?int $poolSize,
    ) {
    }

    public static function of(string $id, string $name, ?int $poolSize): self
    {
        return new self($id, $name, $poolSize);
    }
}
