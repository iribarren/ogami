<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\TrackerLevelView;
use OpenApi\Attributes as OA;

#[OA\Schema(required: ['upTo', 'label'])]
final readonly class TrackerLevelResponse
{
    private function __construct(
        #[OA\Property(description: 'The highest value of the level; null for the last level, which catches the rest.', example: 2, nullable: true)]
        public ?int $upTo,
        #[OA\Property(example: 'Warm')]
        public string $label,
    ) {
    }

    public static function fromView(TrackerLevelView $view): self
    {
        return new self($view->upTo, $view->label);
    }
}
