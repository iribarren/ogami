<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * No library pack has the key: either nothing does, or it belongs to a GameSystem, which is never a
 * source of library items.
 */
final class LibraryPackNotFound extends \RuntimeException
{
    public static function withKey(string $key): self
    {
        return new self(\sprintf('No library pack "%s".', $key));
    }
}
