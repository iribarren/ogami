<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Transfer;

/**
 * A campaign transfers within its GameSystem only (ADR 0046).
 */
final class TransferToAnotherGameSystem extends \DomainException
{
    public static function of(string $pinnedKey, string $targetKey): self
    {
        return new self(\sprintf('A campaign of "%s" cannot continue in a campaign of "%s".', $pinnedKey, $targetKey));
    }
}
