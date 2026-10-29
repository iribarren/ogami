<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Transfer;

/**
 * The campaign does not play the release the transfer starts on: it is upgraded first (ADR 0035),
 * so that its Characters, Tags and facts fit the release the new campaign plays (ADR 0046).
 */
final class TransferFromOutdatedRelease extends \DomainException
{
    public static function of(int $pinnedVersion, int $latestVersion): self
    {
        return new self(\sprintf('The campaign plays release %d and release %d is the latest: upgrade the campaign first.', $pinnedVersion, $latestVersion));
    }
}
