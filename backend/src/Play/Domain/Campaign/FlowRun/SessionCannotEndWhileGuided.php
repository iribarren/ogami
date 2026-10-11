<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

/**
 * While guidance is active a session ends only between scenes, at the scene pick: in a scene or a
 * hook the player finishes it first (or pauses guidance).
 */
final class SessionCannotEndWhileGuided extends \DomainException
{
    /**
     * @param string $actual where the FlowRun is, e.g. 'step "plan"'
     */
    public static function at(string $actual): self
    {
        return new self(\sprintf('The FlowRun is at %s: a guided session ends between scenes, at the scene pick.', $actual));
    }
}
