<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Identity;

use App\Play\Application\ThreadIdGenerator;
use App\Play\Domain\Campaign\Lists\ThreadId;
use Symfony\Component\Uid\Uuid;

/**
 * Time-ordered UUID v7 ids, friendly to database indexes.
 */
final readonly class UuidThreadIdGenerator implements ThreadIdGenerator
{
    public function generate(): ThreadId
    {
        return ThreadId::fromString(Uuid::v7()->toRfc4122());
    }
}
