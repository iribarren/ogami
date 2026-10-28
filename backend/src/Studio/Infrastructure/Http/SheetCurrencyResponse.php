<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\SheetCurrencyView;
use OpenApi\Attributes as OA;

/**
 * The currency of a draft's sheet (ADR 0045): the number field Characters spend, and its unit.
 */
#[OA\Schema(required: ['field', 'unit'])]
final readonly class SheetCurrencyResponse
{
    private function __construct(
        #[OA\Property(description: 'The key of the number field that holds the money.', example: 'eddies')]
        public string $field,
        #[OA\Property(description: 'The unit amounts are shown with; null for none.', example: 'eb', nullable: true)]
        public ?string $unit,
    ) {
    }

    public static function fromView(SheetCurrencyView $view): self
    {
        return new self($view->field, $view->unit);
    }
}
