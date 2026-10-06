<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Identity;

use App\Play\Application\CampaignIdGenerator;
use App\Play\Domain\Campaign\CampaignId;
use Symfony\Component\Uid\Uuid;

/**
 * Time-ordered UUID v7 ids, friendly to database indexes.
 */
final readonly class UuidCampaignIdGenerator implements CampaignIdGenerator
{
    public function generate(): CampaignId
    {
        return CampaignId::fromString(Uuid::v7()->toRfc4122());
    }
}
