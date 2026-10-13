<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Characters;

/**
 * The campaign holds no Character with this id.
 */
final class UnknownCharacter extends \DomainException
{
    public static function withId(CharacterId $id): self
    {
        return new self(\sprintf('Character "%s" not found.', $id->toString()));
    }
}
