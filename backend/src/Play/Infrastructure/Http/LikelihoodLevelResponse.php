<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\LikelihoodLevelView;
use OpenApi\Attributes as OA;

#[OA\Schema(required: ['key', 'label'])]
final readonly class LikelihoodLevelResponse
{
    private function __construct(
        #[OA\Property(description: 'The likelihood to ask the oracle with.', example: 'likely')]
        public string $key,
        #[OA\Property(example: 'Likely')]
        public string $label,
    ) {
    }

    public static function fromView(LikelihoodLevelView $view): self
    {
        return new self($view->key, $view->label);
    }
}
