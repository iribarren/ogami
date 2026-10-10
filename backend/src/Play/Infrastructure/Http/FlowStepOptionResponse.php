<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\GameSystem\Flow\ChoiceOption;
use OpenApi\Attributes as OA;

/**
 * One option of a choice step. What picking it does (effects, branches) stays on the server.
 */
#[OA\Schema(required: ['key', 'label'])]
final readonly class FlowStepOptionResponse
{
    private function __construct(
        #[OA\Property(example: 'yes')]
        public string $key,
        #[OA\Property(example: 'Yes')]
        public string $label,
    ) {
    }

    public static function of(ChoiceOption $option): self
    {
        return new self($option->key, $option->label);
    }
}
