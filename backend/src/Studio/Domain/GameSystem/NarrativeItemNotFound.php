<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

/**
 * The draft has no tracker, tag, fact slot, Scene Type or flow with this key.
 */
final class NarrativeItemNotFound extends \DomainException
{
    public static function withKey(NarrativeSection $section, string $key): self
    {
        return new self(\sprintf('The draft has no %s "%s".', $section->noun(), $key));
    }
}
