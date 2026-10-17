<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\CheckFieldView;
use OpenApi\Attributes as OA;

/**
 * A sheet field a check formula may read, as the formula editor lists it.
 */
#[OA\Schema(required: ['key', 'label', 'type', 'max'])]
final readonly class CheckFieldResponse
{
    private function __construct(
        #[OA\Property(description: 'The field\'s key, the one in "{key}" of a formula.', example: 'hands')]
        public string $key,
        #[OA\Property(example: 'Hands')]
        public string $label,
        #[OA\Property(description: 'A track is also readable as {key.max}.', example: 'number', enum: ['number', 'track', 'derived'])]
        public string $type,
        #[OA\Property(description: 'A track\'s max; null when it declares none or the field is not a track.', example: 6, nullable: true)]
        public ?int $max,
    ) {
    }

    public static function fromView(CheckFieldView $view): self
    {
        return new self($view->key, $view->label, $view->type, $view->max);
    }
}
