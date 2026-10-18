<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Application\InvitationIdGenerator;
use App\Identity\Domain\InvitationId;
use Symfony\Component\Uid\Uuid;

/**
 * Time-ordered UUID v7 ids, friendly to database indexes.
 */
final readonly class UuidInvitationIdGenerator implements InvitationIdGenerator
{
    public function generate(): InvitationId
    {
        return InvitationId::fromString(Uuid::v7()->toRfc4122());
    }
}
