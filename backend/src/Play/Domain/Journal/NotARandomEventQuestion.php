<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

/**
 * A roll for a random event named a journal entry that is not a likelihood answer whose random
 * event is the oracle table rolled, or one recorded in another session or scene than the current one.
 */
final class NotARandomEventQuestion extends \DomainException
{
    public static function of(string $entryId, string $oracleKey): self
    {
        return new self(\sprintf('Journal entry "%s" is not a likelihood answer with the random event "%s".', $entryId, $oracleKey));
    }

    public static function notInCurrentScene(string $entryId): self
    {
        return new self(\sprintf('Journal entry "%s" was not recorded in the current scene, so its random event can no longer be rolled.', $entryId));
    }
}
