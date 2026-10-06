<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

final class InvalidCampaignId extends \DomainException
{
    public static function blank(): self
    {
        return new self('A campaign id must not be blank.');
    }
}
