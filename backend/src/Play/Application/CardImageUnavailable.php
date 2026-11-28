<?php

declare(strict_types=1);

namespace App\Play\Application;

/**
 * The release the campaign is pinned to pins this image, but its bytes are missing from storage: a
 * data integrity fault, not a hash the caller got wrong (that is CardImageNotFound).
 */
final class CardImageUnavailable extends \RuntimeException
{
    public static function withHash(string $sha256, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('The image "%s" of the release this campaign plays is missing from storage.', $sha256), previous: $previous);
    }
}
