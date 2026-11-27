<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Image;

use App\Studio\Application\ImageNormalizer;
use App\Studio\Application\StoredImage;
use App\Studio\Domain\Image\CardImage;
use App\Studio\Domain\Image\ImageRefusalReason;
use App\Studio\Domain\Image\ImageRefused;

/**
 * Checks an upload from its header, decodes it with GD and encodes the pixels again, so no metadata
 * or trailing bytes survive (ADR 0053). The pixel limits are checked before decoding, which keeps a
 * decompression bomb out.
 */
final readonly class GdImageNormalizer implements ImageNormalizer
{
    private const int JPEG_QUALITY = 92;

    public function normalize(string $upload): StoredImage
    {
        $header = '' === $upload ? false : @getimagesizefromstring($upload);
        if (false === $header) {
            throw ImageRefused::because($this->looksLikeAnotherImage($upload) ? ImageRefusalReason::UnsupportedFormat : ImageRefusalReason::Unreadable);
        }
        $type = $header[2];
        if (\IMAGETYPE_PNG !== $type && \IMAGETYPE_JPEG !== $type) {
            throw ImageRefused::because(ImageRefusalReason::UnsupportedFormat);
        }
        CardImage::assertPixels($header[0], $header[1]);

        $image = @imagecreatefromstring($upload);
        if (false === $image) {
            throw ImageRefused::because(ImageRefusalReason::Unreadable);
        }

        $bytes = \IMAGETYPE_PNG === $type ? $this->png($image) : $this->jpeg($image);
        $mediaType = \IMAGETYPE_PNG === $type ? CardImage::PNG : CardImage::JPEG;

        return new StoredImage(CardImage::of(hash('sha256', $bytes), $mediaType, imagesx($image), imagesy($image), \strlen($bytes)), $bytes);
    }

    /**
     * getimagesizefromstring() reads the formats GD cannot decode (SVG is not one of them) as an
     * image with another type; text and garbage are not images at all.
     */
    private function looksLikeAnotherImage(string $upload): bool
    {
        return str_starts_with(ltrim($upload), '<svg') || str_starts_with(ltrim($upload), '<?xml');
    }

    private function png(\GdImage $image): string
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $this->capture(static fn (): bool => imagepng($image));
    }

    private function jpeg(\GdImage $image): string
    {
        return $this->capture(static fn (): bool => imagejpeg($image, null, self::JPEG_QUALITY));
    }

    /**
     * @param callable(): bool $encode
     */
    private function capture(callable $encode): string
    {
        ob_start();
        $encoded = $encode();
        $bytes = (string) ob_get_clean();
        if (!$encoded || '' === $bytes) {
            throw ImageRefused::because(ImageRefusalReason::Unreadable);
        }

        return $bytes;
    }
}
