<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * A release pins an image whose bytes are no longer in storage. Unlike CardImageNotFound (a hash the
 * release never pinned, the caller's mistake) this is a data integrity fault, never a 404.
 */
final class PublishedCardImageMissing extends \RuntimeException
{
    public static function withHash(string $sha256): self
    {
        return new self(\sprintf('The image "%s" is pinned by its release but missing from storage.', $sha256));
    }
}
