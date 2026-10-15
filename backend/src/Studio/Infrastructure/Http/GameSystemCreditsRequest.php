<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * The credits of a draft, in the release contract's shape.
 */
#[OA\Schema(required: ['notice'])]
final readonly class GameSystemCreditsRequest
{
    public function __construct(
        #[OA\Property(description: 'The text Play shows, such as a publisher disclaimer.', example: 'Unofficial fan content.')]
        public string $notice,
        #[OA\Property(description: 'The name of the license or policy.', example: 'CC BY 4.0', nullable: true)]
        public ?string $license,
        #[OA\Property(description: 'An absolute https:// URL of the license or policy.', example: 'https://example.com/license', nullable: true)]
        public ?string $url,
    ) {
    }
}
