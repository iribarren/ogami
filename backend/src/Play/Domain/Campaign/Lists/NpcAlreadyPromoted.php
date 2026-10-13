<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Lists;

/**
 * The NPC was promoted to a Character already: it stays closed and is promoted only once.
 */
final class NpcAlreadyPromoted extends \DomainException
{
    public static function promote(NpcId $id): self
    {
        return new self(\sprintf('NPC "%s" was already promoted to a Character.', $id->toString()));
    }

    public static function reopen(NpcId $id): self
    {
        return new self(\sprintf('NPC "%s" was promoted to a Character and stays closed.', $id->toString()));
    }
}
