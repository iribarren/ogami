<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release;

final class InvalidReleaseId extends \DomainException
{
    public static function blank(): self
    {
        return new self('A release id must not be blank.');
    }
}
