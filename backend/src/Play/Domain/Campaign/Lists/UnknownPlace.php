<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Lists;

/**
 * The campaign holds no Place with this id.
 */
final class UnknownPlace extends \DomainException
{
    public static function withId(PlaceId $id): self
    {
        return new self(\sprintf('Place "%s" not found.', $id->toString()));
    }
}
