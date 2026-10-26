<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\CheckConditionOptionView;
use OpenApi\Attributes as OA;

/**
 * An option of a conditions sheet field.
 */
#[OA\Schema(required: ['key', 'label'])]
final readonly class CheckConditionOptionResponse
{
    private function __construct(
        #[OA\Property(description: 'The option\'s key, the "value" of a mark or clear effect.', example: 'shaken')]
        public string $key,
        #[OA\Property(example: 'Shaken')]
        public string $label,
    ) {
    }

    public static function fromView(CheckConditionOptionView $view): self
    {
        return new self($view->key, $view->label);
    }
}
