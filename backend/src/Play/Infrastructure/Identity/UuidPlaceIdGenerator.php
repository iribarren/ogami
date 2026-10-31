<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Identity;

use App\Play\Application\PlaceIdGenerator;
use App\Play\Domain\Campaign\Lists\PlaceId;
use Symfony\Component\Uid\Uuid;

/**
 * Time-ordered UUID v7 ids, friendly to database indexes.
 */
final readonly class UuidPlaceIdGenerator implements PlaceIdGenerator
{
    public function generate(): PlaceId
    {
        return PlaceId::fromString(Uuid::v7()->toRfc4122());
    }
}
