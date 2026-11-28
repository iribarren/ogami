<?php

declare(strict_types=1);

namespace App\Play\Application;

/**
 * The release the campaign is pinned to did not pin an image with this hash.
 */
final class CardImageNotFound extends \RuntimeException
{
    public static function withHash(string $sha256, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('No image "%s" in the release this campaign plays.', $sha256), previous: $previous);
    }
}
