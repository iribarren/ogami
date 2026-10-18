<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

/**
 * The library pack has no item with this section and key.
 */
final class LibraryItemNotFound extends \DomainException
{
    public static function inPack(LibraryItemRef $item, string $pack): self
    {
        return new self(\sprintf('The library pack "%s" has no %s.', $pack, $item->describe()));
    }
}
