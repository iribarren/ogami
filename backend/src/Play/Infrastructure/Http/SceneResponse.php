<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\SceneView;
use OpenApi\Attributes as OA;

#[OA\Schema(required: ['number', 'title', 'startedAt'])]
final readonly class SceneResponse
{
    private function __construct(
        #[OA\Property(description: 'Numbered from 1 within its session.', example: 1)]
        public int $number,
        #[OA\Property(example: 'At the gate')]
        public string $title,
        #[OA\Property(format: 'date-time')]
        public string $startedAt,
    ) {
    }

    public static function fromView(SceneView $view): self
    {
        return new self($view->number, $view->title, $view->startedAt->format(\DATE_ATOM));
    }
}
