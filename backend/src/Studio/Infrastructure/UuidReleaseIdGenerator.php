<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure;

use App\Studio\Application\ReleaseIdGenerator;
use App\Studio\Domain\Release\ReleaseId;
use Symfony\Component\Uid\Uuid;

/**
 * Time-ordered UUID v7 ids, friendly to database indexes.
 */
final readonly class UuidReleaseIdGenerator implements ReleaseIdGenerator
{
    public function generate(): ReleaseId
    {
        return ReleaseId::fromString(Uuid::v7()->toRfc4122());
    }
}
