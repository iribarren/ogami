<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

/**
 * The draft's sheet has several point budgets, so the single-budget commands cannot say which one
 * they mean: they work on a draft with at most one (ADR 0043).
 */
final class SeveralPointBudgets extends \DomainException
{
    public static function in(int $count): self
    {
        return new self(\sprintf('The sheet has %d point budgets; address one by its key.', $count));
    }
}
