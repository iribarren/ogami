<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Studio\Domain\Image\ImageRefused;

/**
 * Port: turns an upload into a clean image of a known type, or refuses it.
 */
interface ImageNormalizer
{
    /**
     * Re-encodes the upload, dropping everything but its pixels. The hash is of the new bytes.
     *
     * @throws ImageRefused when the upload is not a PNG or JPEG the rules accept
     */
    public function normalize(string $upload): StoredImage;
}
