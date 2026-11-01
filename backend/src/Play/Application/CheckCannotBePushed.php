<?php

declare(strict_types=1);

namespace App\Play\Application;

/**
 * The journal entry cannot be pushed as it stands (ADR 0041): the push of a roll is allowed on the
 * campaign's latest entry, a pool or challenge check rolled from the sheet, or on the roll a check step waits
 * on; once, while a die failed (a pool) or the momentum beats more challenge dice than the score (a burn,
 * ADR 0049), and only while the pinned release still declares that check with that pool or challenge and a push.
 */
final class CheckCannotBePushed extends \DomainException
{
    public static function notTheLatestEntry(): self
    {
        return new self('Only the latest entry of the journal can be pushed.');
    }

    public static function notAPushableCheck(): self
    {
        return new self('Only the roll of a pool check or of a challenge check can be pushed.');
    }

    public static function recordedByAFlowStep(): self
    {
        return new self('A roll recorded by a Flow step cannot be pushed from the sheet.');
    }

    public static function noFailedDie(): self
    {
        return new self('No regular die of the roll failed: there is nothing to re-roll.');
    }

    public static function burnDoesNotImprove(): self
    {
        return new self('The momentum on the sheet no longer beats more challenge dice than the score of the roll: there is nothing to burn.');
    }

    public static function checkChanged(string $checkKey): self
    {
        return new self(\sprintf('The pinned release has no pushable pool check "%s" with the pool that was rolled.', $checkKey));
    }

    public static function challengeCheckChanged(string $checkKey): self
    {
        return new self(\sprintf('The pinned release has no burnable challenge check "%s" with the momentum and challenge dice that were rolled.', $checkKey));
    }

    public static function characterGone(string $characterId): self
    {
        return new self(\sprintf('The Character "%s" who rolled is no longer in the campaign.', $characterId));
    }
}
