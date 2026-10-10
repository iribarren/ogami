<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

/**
 * A mandatory step is a hard gate: it has no Skip (ADR 0017 §3).
 */
final class StepCannotBeSkipped extends \DomainException
{
    public static function mandatory(string $step): self
    {
        return new self(\sprintf('Step "%s" is mandatory: complete it.', $step));
    }
}
