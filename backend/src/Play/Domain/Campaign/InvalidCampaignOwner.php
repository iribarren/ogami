<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

final class InvalidCampaignOwner extends \DomainException
{
    public static function blank(): self
    {
        return new self('A campaign owner id must not be blank.');
    }
}
