<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * The push of a pool check: re-roll the dice that failed once, at a cost (ADR 0041). Documents the
 * contract only.
 *
 * Optional fields are nullable with no PHP default (see GameSystemOracleTableEntry).
 */
#[OA\Schema(description: 'Only on a check with a pool. A push is offered when a die failed.', required: ['label'])]
final readonly class GameSystemCheckPush
{
    /**
     * @param list<array<string, mixed>>|null $effects
     */
    public function __construct(
        #[OA\Property(description: 'What the push costs, in the words of the check.', example: 'Take a Condition', maxLength: 100, minLength: 1)]
        public string $label,
        #[OA\Property(description: 'The cost: sheet and tracker effect objects as the release contract defines them (at most 10). A mark without an option lets the player pick one.', type: 'array', items: new OA\Items(type: 'object', additionalProperties: true), nullable: true, maxItems: 10)]
        public ?array $effects,
        #[OA\Property(description: 'The most failed regular dice a push re-rolls, 1 to 100 (ADR 0044); Hunger dice are never re-rolled. Absent: every failed die.', example: 3, nullable: true, maximum: 100, minimum: 1)]
        public ?int $maxDice,
    ) {
    }
}
