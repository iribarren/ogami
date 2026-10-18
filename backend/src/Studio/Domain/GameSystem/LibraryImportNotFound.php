<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

/**
 * The draft has no library import for this item, or not from this pack.
 */
final class LibraryImportNotFound extends \DomainException
{
    public static function for(LibraryItemRef $item, ?string $pack = null): self
    {
        return new self(null === $pack
            ? \sprintf('The draft has no library import of the %s.', $item->describe())
            : \sprintf('The %s was not imported from the library pack "%s".', $item->describe(), $pack));
    }
}
