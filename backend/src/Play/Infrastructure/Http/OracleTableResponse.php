<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\OracleTableView;
use OpenApi\Attributes as OA;

/**
 * An oracle table of the campaign's pinned release.
 */
#[OA\Schema(required: ['key', 'name'])]
final readonly class OracleTableResponse
{
    private function __construct(
        #[OA\Property(example: 'weather')]
        public string $key,
        #[OA\Property(example: 'Weather')]
        public string $name,
    ) {
    }

    public static function fromView(OracleTableView $view): self
    {
        return new self($view->key, $view->name);
    }
}
