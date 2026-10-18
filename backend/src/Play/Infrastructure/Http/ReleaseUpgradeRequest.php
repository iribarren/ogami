<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/campaigns/{campaignId}/release-upgrade`. Documents the contract only: the
 * controller reads the fields from the request.
 */
#[OA\Schema(required: ['toVersion', 'fingerprint'])]
final readonly class ReleaseUpgradeRequest
{
    public function __construct(
        #[OA\Property(description: 'The release version to upgrade to: the "version" of the previewed plan\'s "to". Only the latest release is an upgrade target.', example: 3, minimum: 1)]
        public int $toVersion,
        #[OA\Property(description: 'The "fingerprint" of the previewed plan the player confirmed.', example: '9f2c4e0a7b1d3c5e8f6a0b2d4c6e8f1a3b5d7c9e0f2a4b6c8d0e1f3a5b7c9d1e')]
        public string $fingerprint,
    ) {
    }
}
