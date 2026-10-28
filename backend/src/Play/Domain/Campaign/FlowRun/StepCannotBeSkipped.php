<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

/**
 * A mandatory step is a hard gate: it has no Skip (ADR 0017 §3). A shop step has none either: an
 * empty transaction leaves it (ADR 0045).
 */
final class StepCannotBeSkipped extends \DomainException
{
    public static function mandatory(string $step): self
    {
        return new self(\sprintf('Step "%s" is mandatory: complete it.', $step));
    }

    public static function shop(string $step): self
    {
        return new self(\sprintf('Shop step "%s" is never skipped: leave it with an empty transaction.', $step));
    }
}
