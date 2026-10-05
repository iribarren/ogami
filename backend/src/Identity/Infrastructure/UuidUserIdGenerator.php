<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Application\UserIdGenerator;
use App\Identity\Domain\UserId;
use Symfony\Component\Uid\Uuid;

/**
 * Time-ordered UUID v7 ids, friendly to database indexes.
 */
final readonly class UuidUserIdGenerator implements UserIdGenerator
{
    public function generate(): UserId
    {
        return UserId::fromString(Uuid::v7()->toRfc4122());
    }
}
