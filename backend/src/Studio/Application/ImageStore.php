<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * Port: where image bytes are kept, addressed by the sha256 of the bytes (ADR 0053).
 */
interface ImageStore
{
    /**
     * Holds off every other writer of these images until the surrounding transaction ends. The
     * hashes are locked in one global order (sorted, each once), whatever order they come in, so
     * two commands that lock the same images in opposite orders cannot wait on each other.
     * A command takes all of its image locks in one call, after the GameSystem lock, and never
     * locks another image later.
     *
     * @param list<string> $sha256s
     */
    public function lockImages(array $sha256s): void;

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
