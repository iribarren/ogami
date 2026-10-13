<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Lists\NpcId;

/**
 * A Scene's cast lists an NPC or a Character twice, or its Characters name one the campaign does not hold. Nothing is deduplicated silently.
 */
final class InvalidSceneCast extends \DomainException
{
    public static function listedTwice(NpcId $id): self
    {
        return new self(\sprintf('NPC "%s" is in the cast twice.', $id->toString()));
    }

    public static function characterListedTwice(CharacterId $id): self
    {
        return new self(\sprintf('Character "%s" is in the cast twice.', $id->toString()));
    }

    public static function unknownCharacter(int $session, int $scene, CharacterId $id): self
    {
        return new self(\sprintf('Scene %d of session %d casts Character "%s", which the campaign does not hold.', $scene, $session, $id->toString()));
    }
}
