<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Facts;

/**
 * A fact was filled into a Fact slot key that the campaign's pinned release does not declare.
 */
final class UnknownFactSlot extends \DomainException
{
    public static function withKey(string $key): self
    {
        return new self(\sprintf('Fact slot "%s" not found in the pinned release.', $key));
    }
}
