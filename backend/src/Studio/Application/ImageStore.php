<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * Port: where image bytes are kept, addressed by the sha256 of the bytes (ADR 0053).
 */
interface ImageStore
{
    /**
     * Keeps the image; the same hash again changes nothing.
     */
    public function put(StoredImage $image): void;

    /**
     * @throws \UnexpectedValueException when the stored row breaks the image rules
     */
    public function get(string $sha256): ?StoredImage;

    /**
     * Deletes the image unless a GameSystem still uses it.
     */
    public function removeIfUnreferenced(string $sha256): void;
}
