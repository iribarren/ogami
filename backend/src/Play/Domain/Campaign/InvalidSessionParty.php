<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

use App\Play\Domain\Campaign\Characters\CharacterId;

/**
 * A Session's party lists a Character twice, or names one the campaign does not hold. Nothing is
 * deduplicated silently.
 */
final class InvalidSessionParty extends \DomainException
{
    public static function listedTwice(CharacterId $id): self
    {
        return new self(\sprintf('Character "%s" is in the party twice.', $id->toString()));
    }

    public static function unknownCharacter(int $session, CharacterId $id): self
    {
        return new self(\sprintf('The party of session %d names Character "%s", which the campaign does not hold.', $session, $id->toString()));
    }
}
