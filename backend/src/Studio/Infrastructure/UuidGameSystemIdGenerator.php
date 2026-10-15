<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure;

use App\Studio\Application\GameSystemIdGenerator;
use App\Studio\Domain\GameSystem\GameSystemId;
use Symfony\Component\Uid\Uuid;

/**
 * Time-ordered UUID v7 ids, friendly to database indexes.
 */
final readonly class UuidGameSystemIdGenerator implements GameSystemIdGenerator
{
    public function generate(): GameSystemId
    {
        return GameSystemId::fromString(Uuid::v7()->toRfc4122());
    }
}
