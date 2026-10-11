<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Lists;

/**
 * An NPC or Thread was given a Tag key that the campaign's pinned release does not define.
 */
final class UnknownReleaseTag extends \DomainException
{
    public static function withKey(string $key): self
    {
        return new self(\sprintf('Tag "%s" not found in the pinned release.', $key));
    }
}
