<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * The push a test-rolled pool check declares (ADR 0041). Its cost is never applied in a test roll,
 * so only its label is told.
 */
#[OA\Schema(required: ['label'])]
final readonly class CheckTestRollPushResponse
{
    private function __construct(
        #[OA\Property(description: 'What the push costs, in the words of the check.', example: 'Take a Condition')]
        public string $label,
    ) {
    }

    public static function of(string $label): self
    {
        return new self($label);
    }
}
