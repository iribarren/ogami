<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

final class InvalidCampaignName extends \DomainException
{
    public static function blank(): self
    {
        return new self('A campaign name must not be blank.');
    }

    public static function tooLong(int $maxLength, int $length): self
    {
        return new self(\sprintf('A campaign name must be at most %d characters, got %d.', $maxLength, $length));
    }
}
