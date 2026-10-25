<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\ConditionOptionView;
use OpenApi\Attributes as OA;

/**
 * One option of a conditions field of the character sheet.
 */
#[OA\Schema(required: ['key', 'label'])]
final readonly class ConditionOptionResponse
{
    private function __construct(
        #[OA\Property(description: 'The key a Character\'s marked list holds.', example: 'hurt')]
        public string $key,
        #[OA\Property(example: 'Hurt')]
        public string $label,
    ) {
    }

    public static function fromView(ConditionOptionView $view): self
    {
        return new self($view->key, $view->label);
    }
}
