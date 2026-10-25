<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

/**
 * A push kept as the campaign's latest entry found a later entry when it was saved (ADR 0041): the
 * sheet pushes only the latest entry, and another request recorded one meanwhile.
 */
final class JournalEntryNotTheLatest extends \DomainException
{
    public static function entry(JournalEntryId $id): self
    {
        return new self(\sprintf('Journal entry "%s" is no longer the latest of its campaign.', $id->toString()));
    }
}
