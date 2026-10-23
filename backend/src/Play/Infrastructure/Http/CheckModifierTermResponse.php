<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * One value a check's modifier formula read when the check was performed.
 */
#[OA\Schema(required: ['reference', 'label', 'value'])]
final readonly class CheckModifierTermResponse
{
    private function __construct(
        #[OA\Property(description: 'The reference as the formula spells it, braces included.', example: '{hot}')]
        public string $reference,
        #[OA\Property(description: 'What players call it: the sheet field\'s label, the track\'s label plus " max", or the Tracker\'s name.', example: 'Hot')]
        public string $label,
        #[OA\Property(description: 'The value it had when the check was performed.', example: 2)]
        public int $value,
    ) {
    }

    /**
     * @param array{reference: string, label: string, value: int} $term
     */
    public static function of(array $term): self
    {
        return new self($term['reference'], $term['label'], $term['value']);
    }
}
