<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem;

/**
 * The campaign's pinned release declares no Scene Type with this key.
 */
final class UnknownSceneType extends \DomainException
{
    public static function withKey(string $key): self
    {
        return new self(\sprintf('Scene Type "%s" not found.', $key));
    }
}
