<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\ReleaseCardImageView;
use OpenApi\Attributes as OA;

/**
 * One image the campaign's pinned release pinned, in the slot it is used for. Same fields as
 * Studio's card image, with the url of the campaign's own read path.
 */
#[OA\Schema(required: ['slot', 'sha256', 'mediaType', 'width', 'height', 'url'])]
final readonly class CampaignCardImageResponse
{
    private function __construct(
        #[OA\Property(description: 'Where the image is used on a card.', enum: ['cardBack', 'cardFront'])]
        public string $slot,
        #[OA\Property(description: 'The hash of the stored bytes, 64 hexadecimal digits.')]
        public string $sha256,
        #[OA\Property(enum: ['image/png', 'image/jpeg'])]
        public string $mediaType,
        #[OA\Property(description: 'In pixels.')]
        public int $width,
        #[OA\Property(description: 'In pixels.')]
        public int $height,
        #[OA\Property(description: 'Where to read the image bytes.')]
        public string $url,
    ) {
    }

    public static function fromView(ReleaseCardImageView $image, string $campaignId): self
    {
        return new self(
            $image->slot,
            $image->sha256,
            $image->mediaType,
            $image->width,
            $image->height,
            \sprintf('/api/campaigns/%s/card-images/%s', rawurlencode($campaignId), $image->sha256),
        );
    }
}
