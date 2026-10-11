<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Lists;

/**
 * The campaign holds no Thread with this id.
 */
final class UnknownThread extends \DomainException
{
    public static function withId(ThreadId $id): self
    {
        return new self(\sprintf('Thread "%s" not found.', $id->toString()));
    }
}
