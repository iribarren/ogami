<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

/**
 * A result a note attaches breaks a rule: the note records nothing.
 */
final class InvalidNoteAttachment extends \DomainException
{
    public static function blankId(): self
    {
        return new self('An attached journal entry id must not be blank.');
    }

    public static function duplicate(string $entryId): self
    {
        return new self(\sprintf('Journal entry "%s" is attached twice.', $entryId));
    }

    public static function tooMany(int $max, int $count): self
    {
        return new self(\sprintf('A note attaches at most %d results, %d given.', $max, $count));
    }

    public static function notInCampaign(string $entryId): self
    {
        return self::cannotBeAttached($entryId, 'it is not an entry of this campaign');
    }

    public static function anotherScene(string $entryId): self
    {
        return self::cannotBeAttached($entryId, 'it was recorded in another scene than the current one');
    }

    public static function notAttachableKind(string $entryId, string $kind): self
    {
        return self::cannotBeAttached($entryId, \sprintf('a %s result cannot be attached', $kind));
    }

    public static function randomEvent(string $entryId): self
    {
        return self::cannotBeAttached($entryId, 'a random event result already belongs to its question');
    }

    private static function cannotBeAttached(string $entryId, string $reason): self
    {
        return new self(\sprintf('Journal entry "%s" cannot be attached: %s.', $entryId, $reason));
    }
}
