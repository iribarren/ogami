<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Domain\Check\CheckTestSpecialResult;
use OpenApi\Attributes as OA;

/**
 * The push a test-rolled pool check declares (ADR 0041). Its cost is never applied in a test roll,
 * so only its label, its cap and the special result of the roll before it are told.
 */
#[OA\Schema(required: ['label', 'maxDice', 'rolledSpecial'])]
final readonly class CheckTestRollPushResponse
{
    private function __construct(
        #[OA\Property(description: 'What the push costs, in the words of the check.', example: 'Take a Condition')]
        public string $label,
        #[OA\Property(description: 'The most failed regular dice a push re-rolls (ADR 0044); null re-rolls every one. Hunger dice are never re-rolled.', example: 3, nullable: true)]
        public ?int $maxDice,
        #[OA\Property(description: 'Only on a pushed roll: the special result the roll had before the push; null for none, and before a push.', nullable: true)]
        public ?CheckTestSpecialResult $rolledSpecial,
    ) {
    }

    public static function of(string $label, ?int $maxDice, ?CheckTestSpecialResult $rolledSpecial): self
    {
        return new self($label, $maxDice, $rolledSpecial);
    }
}
