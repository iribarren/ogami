<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

/**
 * A FlowRun command names the position it acts on (a step key, open play in a scene, the scene
 * pick), and the FlowRun is elsewhere: the command is stale, e.g. sent twice or from another tab.
 */
final class FlowRunPositionMismatch extends \DomainException
{
    /**
     * @param string $expected where the command acts, e.g. 'step "plan"'
     * @param string $actual   where the FlowRun is
     */
    public static function at(string $expected, string $actual): self
    {
        return new self(\sprintf('The FlowRun is at %s, not at %s.', $actual, $expected));
    }

    public static function sceneNotCurrent(): self
    {
        return new self('The guided scene is no longer the current scene: pause and resume guidance to go on at the scene pick.');
    }
}
