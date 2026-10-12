<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

use App\Play\Domain\Campaign\Lists\NpcId;

/**
 * A Scene's cast lists an NPC twice. Nothing is deduplicated silently.
 */
final class InvalidSceneCast extends \DomainException
{
    public static function listedTwice(NpcId $id): self
    {
        return new self(\sprintf('NPC "%s" is in the cast twice.', $id->toString()));
    }
}
