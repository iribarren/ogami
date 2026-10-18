<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Application\PasswordResetIdGenerator;
use App\Identity\Domain\PasswordResetId;
use Symfony\Component\Uid\Uuid;

/**
 * Time-ordered UUID v7 ids, friendly to database indexes.
 */
final readonly class UuidPasswordResetIdGenerator implements PasswordResetIdGenerator
{
    public function generate(): PasswordResetId
    {
        return PasswordResetId::fromString(Uuid::v7()->toRfc4122());
    }
}
