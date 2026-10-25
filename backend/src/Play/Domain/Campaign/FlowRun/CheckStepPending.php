<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

/**
 * The current check step's roll waits for Keep or Push (ADR 0041): nothing else moves the FlowRun
 * until the player resolves it.
 */
final class CheckStepPending extends \DomainException
{
    public static function resolveFirst(): self
    {
        return new self('Keep or push the pending roll first.');
    }
}
