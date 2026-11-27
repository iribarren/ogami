<?php

declare(strict_types=1);

namespace App\Studio\Domain\Image;

/**
 * What is known about a stored card image: the hash of its bytes, its type and its size. The rules
 * of ADR 0053 hold for every instance, so a stored row that breaks them is refused when read back.
 */
final readonly class CardImage
{
    public const int MAX_UPLOAD_BYTES = 5 * 1024 * 1024;
    public const int RECOMMENDED_WIDTH = 815;
    public const int RECOMMENDED_HEIGHT = 1110;
    public const int MIN_WIDTH = 408;
    public const int MIN_HEIGHT = 555;
    public const int MAX_WIDTH = 1630;
    public const int MAX_HEIGHT = 2220;
    /** How far width / height may stray from 69 / 94, as a fraction. */
    public const float ASPECT_TOLERANCE = 0.02;
    public const string PNG = 'image/png';
    public const string JPEG = 'image/jpeg';

    private function __construct(
        public string $sha256,
        public string $mediaType,
        public int $width,
        public int $height,
        public int $byteSize,
    ) {
    }

    /**
     * @throws ImageRefused              when the type or the pixels break the rules
     * @throws \InvalidArgumentException when the hash or the byte size is not one
     */
    public static function of(string $sha256, string $mediaType, int $width, int $height, int $byteSize): self
    {
        if (1 !== preg_match('/\A[0-9a-f]{64}\z/', $sha256)) {
            throw new \InvalidArgumentException('An image hash is 64 lower-case hexadecimal digits.');
        }
        if ($byteSize < 1) {
            throw new \InvalidArgumentException('An image has at least one byte.');
        }
        if (!\in_array($mediaType, [self::PNG, self::JPEG], true)) {
            throw ImageRefused::because(ImageRefusalReason::UnsupportedFormat);
        }
        self::assertPixels($width, $height);

        return new self($sha256, $mediaType, $width, $height, $byteSize);
    }

    /**
     * @throws ImageRefused when the pixels are out of range or the proportions are not a card's
     */
    public static function assertPixels(int $width, int $height): void
    {
        if ($width < self::MIN_WIDTH || $height < self::MIN_HEIGHT) {
            throw ImageRefused::because(ImageRefusalReason::TooFewPixels);
        }
        if ($width > self::MAX_WIDTH || $height > self::MAX_HEIGHT) {
            throw ImageRefused::because(ImageRefusalReason::TooManyPixels);
        }
        if (abs($width / $height / (69 / 94) - 1) > self::ASPECT_TOLERANCE) {
            throw ImageRefused::because(ImageRefusalReason::WrongAspectRatio);
        }
    }

    /**
     * @throws ImageRefused when the upload is over the limit
     */
    public static function assertUploadSize(int $bytes): void
    {
        if ($bytes > self::MAX_UPLOAD_BYTES) {
            throw ImageRefused::because(ImageRefusalReason::TooLarge);
        }
    }
}
