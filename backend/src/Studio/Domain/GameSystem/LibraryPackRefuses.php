<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

/**
 * A library pack holds oracles, trackers, tags, fact slots and Scene Types only (ADR 0033); this
 * operation belongs to a GameSystem. The pack is left as it was.
 */
final class LibraryPackRefuses extends \DomainException
{
    public static function to(string $operation, string $key): self
    {
        return new self(\sprintf('The library pack "%s" cannot %s; a pack holds oracles, trackers, tags, fact slots and Scene Types only.', $key, $operation));
    }
}
