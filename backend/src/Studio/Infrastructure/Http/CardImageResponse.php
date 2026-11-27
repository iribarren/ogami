<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Domain\Image\CardImagePin;
use OpenApi\Attributes as OA;

/**
 * One image of a GameSystem draft, in the slot it is used for.
 */
#[OA\Schema(required: ['slot', 'sha256', 'mediaType', 'width', 'height', 'url'])]
final readonly class CardImageResponse
{
    private function __construct(
        #[OA\Property(description: 'Where the image is used on a printed card.', enum: ['cardBack', 'cardFront'])]
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

    public static function fromPin(CardImagePin $pin, string $key): self
    {
        return new self(
            $pin->slot->value,
            $pin->image->sha256,
            $pin->image->mediaType,
            $pin->image->width,
            $pin->image->height,
            \sprintf('/api/studio/game-systems/%s/images/%s', rawurlencode($key), $pin->image->sha256),
        );
    }
}
