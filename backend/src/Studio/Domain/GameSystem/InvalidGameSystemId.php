<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

final class InvalidGameSystemId extends \DomainException
{
    public static function notAUuid(): self
    {
        return new self('A GameSystem id must be a UUID.');
    }
}
