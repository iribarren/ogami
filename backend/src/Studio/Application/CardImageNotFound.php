<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * No image with this hash is used by the GameSystem. Also raised for another owner's, so nothing is revealed.
 */
final class CardImageNotFound extends \DomainException
{
    public static function withHash(string $sha256): self
    {
        return new self(\sprintf('No image "%s".', $sha256));
    }
}
