<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

/**
 * The random event of a likelihood answer was already rolled: a fate question has one event, whose
 * oracle table entry is tied to it. Also raised by the repository when two rolls for the same
 * question are recorded at once.
 */
final class RandomEventAlreadyRolled extends \DomainException
{
    public static function forEntry(string $entryId): self
    {
        return new self(\sprintf('The random event of journal entry "%s" was already rolled.', $entryId));
    }
}
