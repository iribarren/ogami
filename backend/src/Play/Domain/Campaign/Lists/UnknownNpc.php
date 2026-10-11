<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Lists;

/**
 * The campaign holds no NPC with this id.
 */
final class UnknownNpc extends \DomainException
{
    public static function withId(NpcId $id): self
    {
        return new self(\sprintf('NPC "%s" not found.', $id->toString()));
    }
}
