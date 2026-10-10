<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

/**
 * Only a loop phase ends by the player's choice, at its scene pick.
 */
final class MoveOnNotAllowed extends \DomainException
{
    public static function oncePhase(string $phase): self
    {
        return new self(\sprintf('Phase "%s" plays once: it ends after its scenes, not by moving on.', $phase));
    }
}
