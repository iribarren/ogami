<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

/**
 * A voluntary spend (a choice option, a prompt or a character step) that would take the release's
 * currency under its floor (ADR 0045). The step does not complete and the campaign stays as it was.
 */
final class InsufficientFunds extends \DomainException
{
    public function __construct(
        public readonly string $field,
        public readonly int $before,
        public readonly int $attempted,
        public readonly int $floor,
    ) {
        parent::__construct(\sprintf('Not enough "%s": the step would take it from %d to %d, under %d.', $field, $before, $attempted, $floor));
    }
}
