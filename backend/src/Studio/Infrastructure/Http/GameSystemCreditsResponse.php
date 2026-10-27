<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Domain\Release\Version2\ReleaseVersion2;
use OpenApi\Attributes as OA;

/**
 * The credits of a draft as stored.
 */
#[OA\Schema(required: ['notice', 'license', 'url', 'badge'])]
final readonly class GameSystemCreditsResponse
{
    private function __construct(
        #[OA\Property(example: 'Unofficial fan content.')]
        public string $notice,
        #[OA\Property(example: 'CC BY 4.0', nullable: true)]
        public ?string $license,
        #[OA\Property(example: 'https://example.com/license', nullable: true)]
        public ?string $url,
        #[OA\Property(example: 'dark-pack', nullable: true, enum: ReleaseVersion2::BADGES)]
        public ?string $badge,
    ) {
    }

    /**
     * @param array<mixed>|null $credits the draft's credits, read defensively: a draft is stored as given
     */
    public static function fromArray(?array $credits): ?self
    {
        if (null === $credits) {
            return null;
        }

        $notice = $credits['notice'] ?? null;
        $license = $credits['license'] ?? null;
        $url = $credits['url'] ?? null;
        $badge = $credits['badge'] ?? null;

        return new self(\is_string($notice) ? $notice : '', \is_string($license) ? $license : null, \is_string($url) ? $url : null, \is_string($badge) ? $badge : null);
    }
}
