<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

/**
 * The library pack an item was imported from no longer has it (or the pack is gone), so there is
 * nothing to update from. The draft's copy stays as it is.
 */
final class LibraryItemRemoved extends \DomainException
{
    public static function from(LibraryItemRef $item, string $pack): self
    {
        return new self(\sprintf('The library pack "%s" no longer has the %s.', $pack, $item->describe()));
    }
}
