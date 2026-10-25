<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

/**
 * A pool check was pushed already (ADR 0041): a roll is pushed once, and the push completes its
 * journal entry for good.
 */
final class CheckAlreadyPushed extends \DomainException
{
    public static function roll(): self
    {
        return new self('This pool roll was pushed already, and a roll is pushed once.');
    }

    public static function entry(JournalEntryId $id): self
    {
        return new self(\sprintf('The check of journal entry "%s" was pushed already, and a roll is pushed once.', $id->toString()));
    }
}
