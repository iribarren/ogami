<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Identity;

use App\Play\Application\CharacterIdGenerator;
use App\Play\Domain\Campaign\Characters\CharacterId;
use Symfony\Component\Uid\Uuid;

/**
 * Time-ordered UUID v7 ids, friendly to database indexes.
 */
final readonly class UuidCharacterIdGenerator implements CharacterIdGenerator
{
    public function generate(): CharacterId
    {
        return CharacterId::fromString(Uuid::v7()->toRfc4122());
    }
}
