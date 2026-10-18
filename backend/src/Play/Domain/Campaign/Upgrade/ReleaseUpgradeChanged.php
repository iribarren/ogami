<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Upgrade;

/**
 * The upgrade the player confirmed is not the one the campaign would get now: the campaign was
 * edited, or another release was published, since the preview. Nothing was changed.
 */
final class ReleaseUpgradeChanged extends \DomainException
{
    public static function sincePreview(): self
    {
        return new self('The upgrade changed since the preview; review it again.');
    }
}
