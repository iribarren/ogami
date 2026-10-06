<?php

declare(strict_types=1);

namespace App\Play\Application;

/**
 * No entry with this id is in the journal of the requested campaign. An entry of another campaign
 * is reported exactly like an unknown one.
 */
final class JournalEntryNotFound extends \RuntimeException
{
    public static function withId(string $entryId): self
    {
        return new self(\sprintf('Journal entry "%s" not found.', $entryId));
    }
}
