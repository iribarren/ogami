<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

/**
 * Another request saved the GameSystem after this one loaded it. Saving would overwrite that
 * change, so the save is refused; the caller reloads and tries again.
 */
final class GameSystemModifiedConcurrently extends \DomainException
{
    public static function withKey(string $key, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('GameSystem "%s" was changed by another request. Reload it and try again.', $key), 0, $previous);
    }
}
