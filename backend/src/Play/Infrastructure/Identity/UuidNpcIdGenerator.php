<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Identity;

use App\Play\Application\NpcIdGenerator;
use App\Play\Domain\Campaign\Lists\NpcId;
use Symfony\Component\Uid\Uuid;

/**
 * Time-ordered UUID v7 ids, friendly to database indexes.
 */
final readonly class UuidNpcIdGenerator implements NpcIdGenerator
{
    public function generate(): NpcId
    {
        return NpcId::fromString(Uuid::v7()->toRfc4122());
    }
}
