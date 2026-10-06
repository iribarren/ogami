<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

final class InvalidJournalEntryId extends \DomainException
{
    public static function blank(): self
    {
        return new self('A journal entry id must not be blank.');
    }
}
