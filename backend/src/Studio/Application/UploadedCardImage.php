<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Studio\Domain\Image\CardImage;

/**
 * Where an UploadCardImage handler leaves the image it stored, so the caller can answer with it
 * without reading the pins again.
 */
final class UploadedCardImage
{
    private ?CardImage $image = null;

    public function store(CardImage $image): void
    {
        $this->image = $image;
    }

    /**
     * @throws \LogicException when the command has not been handled
     */
    public function image(): CardImage
    {
        return $this->image ?? throw new \LogicException('The upload has not been handled.');
    }
}
