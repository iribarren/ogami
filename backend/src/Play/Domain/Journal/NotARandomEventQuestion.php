<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

/**
 * A roll for a random event named a journal entry that is not a likelihood answer whose random
 * event is the oracle table rolled.
 */
final class NotARandomEventQuestion extends \DomainException
{
    public static function of(string $entryId, string $oracleKey): self
    {
        return new self(\sprintf('Journal entry "%s" is not a likelihood answer with the random event "%s".', $entryId, $oracleKey));
    }
}
