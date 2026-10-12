<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Facts;

/**
 * The campaign holds no Campaign fact with this id.
 */
final class UnknownFact extends \DomainException
{
    public static function withId(FactId $id): self
    {
        return new self(\sprintf('Fact "%s" not found.', $id->toString()));
    }
}
