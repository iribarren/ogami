<?php

declare(strict_types=1);

namespace App\Studio\Domain\Image;

/**
 * An image breaks the rules of ADR 0053. The message states the limit and the recommended size.
 */
final class ImageRefused extends \DomainException
{
    private function __construct(
        public readonly ImageRefusalReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function because(ImageRefusalReason $reason): self
    {
        $recommended = \sprintf('The recommended size is %d × %d px (69 × 94 mm at 300 dpi: a 63 × 88 mm card plus 3 mm bleed).', CardImage::RECOMMENDED_WIDTH, CardImage::RECOMMENDED_HEIGHT);

        return new self($reason, match ($reason) {
            ImageRefusalReason::UnsupportedFormat => 'Only PNG and JPEG images are accepted.',
            ImageRefusalReason::Unreadable => 'The file is not a readable PNG or JPEG image.',
            ImageRefusalReason::TooLarge => \sprintf('The image is larger than %d MB.', CardImage::MAX_UPLOAD_BYTES / 1024 / 1024),
            ImageRefusalReason::TooFewPixels => \sprintf('The image must be at least %d × %d px. %s', CardImage::MIN_WIDTH, CardImage::MIN_HEIGHT, $recommended),
            ImageRefusalReason::TooManyPixels => \sprintf('The image must be at most %d × %d px. %s', CardImage::MAX_WIDTH, CardImage::MAX_HEIGHT, $recommended),
            ImageRefusalReason::WrongAspectRatio => \sprintf('The image must have the proportions of a card with bleed, 69:94, give or take %d %%. %s', CardImage::ASPECT_TOLERANCE * 100, $recommended),
        });
    }
}
