<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

/**
 * The current session has no scene (or there is no session), so nothing can be recorded in the
 * journal (every journal entry belongs to the current scene) and no Scene Type can be switched.
 */
final class NoCurrentScene extends \DomainException
{
    public static function toRecordJournalEntry(): self
    {
        return new self('Start a scene before recording a journal entry.');
    }

    public static function toSwitchSceneType(): self
    {
        return new self('Start a scene before switching its Scene Type.');
    }
}
