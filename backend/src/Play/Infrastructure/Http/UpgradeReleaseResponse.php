<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Campaign\PinnedRelease;
use OpenApi\Attributes as OA;

/**
 * A release of the campaign's GameSystem in an Upgrade plan: the one it plays or the one it moves to.
 */
#[OA\Schema(required: ['version', 'name'])]
final readonly class UpgradeReleaseResponse
{
    private function __construct(
        #[OA\Property(example: 3)]
        public int $version,
        #[OA\Property(description: 'The GameSystem\'s name in that release.', example: 'Heist')]
        public string $name,
    ) {
    }

    public static function fromRelease(PinnedRelease $release): self
    {
        return new self($release->releaseVersion(), $release->gameSystemName());
    }
}
