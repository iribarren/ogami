<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * The published release a new draft copies.
 */
#[OA\Schema(required: ['key'])]
final readonly class GameSystemSourceRequest
{
    public function __construct(
        #[OA\Property(description: 'The key of any published GameSystem, a preset\'s included.', example: 'ironsworn')]
        public string $key,
        #[OA\Property(description: 'The release version to copy; absent or null for the latest.', example: 2, nullable: true)]
        public ?int $version,
    ) {
    }
}
