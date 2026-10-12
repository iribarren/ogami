<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Identity;

use App\Play\Application\FactIdGenerator;
use App\Play\Domain\Campaign\Facts\FactId;
use Symfony\Component\Uid\Uuid;

/**
 * Time-ordered UUID v7 ids, friendly to database indexes.
 */
final readonly class UuidFactIdGenerator implements FactIdGenerator
{
    public function generate(): FactId
    {
        return FactId::fromString(Uuid::v7()->toRfc4122());
    }
}
