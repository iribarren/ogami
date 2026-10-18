<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Upgrade;

/**
 * A campaign upgrades within its GameSystem only (ADR 0035).
 */
final class UpgradeToAnotherGameSystem extends \DomainException
{
    public static function of(string $pinnedKey, string $targetKey): self
    {
        return new self(\sprintf('A campaign of "%s" cannot upgrade to a release of "%s".', $pinnedKey, $targetKey));
    }
}
