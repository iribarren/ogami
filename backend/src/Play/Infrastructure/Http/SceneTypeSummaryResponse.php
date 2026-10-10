<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\SceneTypeSummaryView;
use OpenApi\Attributes as OA;

/**
 * A Scene Type of the campaign's pinned release.
 */
#[OA\Schema(required: ['key', 'name', 'purpose'])]
final readonly class SceneTypeSummaryResponse
{
    private function __construct(
        #[OA\Property(example: 'legwork')]
        public string $key,
        #[OA\Property(example: 'Legwork')]
        public string $name,
        #[OA\Property(example: 'Learn about the target.')]
        public string $purpose,
    ) {
    }

    public static function fromView(SceneTypeSummaryView $view): self
    {
        return new self($view->key, $view->name, $view->purpose);
    }
}
