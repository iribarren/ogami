<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

/**
 * The draft's sheet has no point budget with this key (a legacy single budget is the key "points").
 */
final class SheetPointBudgetNotFound extends \DomainException
{
    public static function withKey(string $key): self
    {
        return new self(\sprintf('The sheet has no point budget "%s".', $key));
    }
}
