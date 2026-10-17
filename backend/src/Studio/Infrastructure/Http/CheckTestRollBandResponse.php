<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\CheckTestRollBandView;
use OpenApi\Attributes as OA;

/**
 * An Outcome band of a test-rolled check with the upper bound it had for the samples.
 */
#[OA\Schema(required: ['key', 'label', 'bound'])]
final readonly class CheckTestRollBandResponse
{
    private function __construct(
        #[OA\Property(example: 'success')]
        public string $key,
        #[OA\Property(example: 'Success')]
        public string $label,
        #[OA\Property(description: 'The band\'s upper bound for these samples (a formula or tracker bound evaluated, difficulty included); null for the last band, which catches the rest. Bounds that do not increase are returned as they are.', example: 17, nullable: true)]
        public ?int $bound,
    ) {
    }

    public static function fromView(CheckTestRollBandView $view): self
    {
        return new self($view->key, $view->label, $view->bound);
    }
}
