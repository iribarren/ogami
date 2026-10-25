<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

/**
 * Keep or Push names a check step roll the FlowRun is not waiting on (ADR 0041): no roll pends, e.g.
 * the command was sent twice or from another tab.
 */
final class NoPendingCheck extends \DomainException
{
    public static function toResolve(): self
    {
        return new self('No roll of a check step waits for Keep or Push.');
    }
}
