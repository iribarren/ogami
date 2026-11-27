<?php

declare(strict_types=1);

namespace App\Studio\Domain\Image;

/**
 * Why an image is refused. The values are the stable reason codes of the API.
 */
enum ImageRefusalReason: string
{
    case MissingFile = 'missing_file';
    case UnsupportedFormat = 'unsupported_format';
    case Unreadable = 'unreadable';
    case TooLarge = 'too_large';
    case TooFewPixels = 'too_few_pixels';
    case TooManyPixels = 'too_many_pixels';
    case WrongAspectRatio = 'wrong_aspect_ratio';
}
