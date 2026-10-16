<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

/**
 * Another item of the same section of the draft already uses this key: keys are unique within
 * their section, not across sections.
 */
final class NarrativeKeyTaken extends \DomainException
{
    public static function withKey(NarrativeSection $section, string $key): self
    {
        return new self(\sprintf('The draft already has a %s "%s".', $section->noun(), $key));
    }
}
