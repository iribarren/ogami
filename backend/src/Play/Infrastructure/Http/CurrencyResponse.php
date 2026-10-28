<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\GameSystem\Sheet\Currency;
use OpenApi\Attributes as OA;

/**
 * The currency of the sheet (ADR 0045): the number field a Character's money is kept in.
 */
#[OA\Schema(required: ['field', 'unit'])]
final readonly class CurrencyResponse
{
    private function __construct(
        #[OA\Property(description: 'The key of the number field money is kept in.', example: 'eddies')]
        public string $field,
        #[OA\Property(description: 'What follows an amount, such as "eb"; null when the release gives none.', example: 'eb', nullable: true)]
        public ?string $unit,
    ) {
    }

    public static function fromCurrency(?Currency $currency): ?self
    {
        return $currency instanceof Currency ? new self($currency->field, $currency->unit) : null;
    }
}
