<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

/**
 * The draft already has the items requested from the library, imported or authored: a requested
 * key never replaces an existing item. Every clashing item is named.
 */
final class LibraryItemKeyTaken extends \DomainException
{
    /**
     * @param non-empty-list<LibraryItemRef> $conflicts
     */
    public static function for(array $conflicts): self
    {
        return new self(\sprintf('The draft already has %s.', implode(', ', array_map(static fn (LibraryItemRef $item): string => $item->describe(), $conflicts))));
    }
}
