<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

/**
 * Only a loop phase ends by the player's choice ("Move on"), at its scene pick, after a scene or a
 * hook, and not while it is closing.
 */
final class MoveOnNotAllowed extends \DomainException
{
    public static function oncePhase(string $phase): self
    {
        return new self(\sprintf('Phase "%s" plays once: it ends after its scenes, not by moving on.', $phase));
    }

    public static function closing(string $phase): self
    {
        return new self(\sprintf('Phase "%s" is closing: it ends after its phase closing.', $phase));
    }
}
