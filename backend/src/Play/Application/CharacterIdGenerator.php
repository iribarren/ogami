<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\Characters\CharacterId;

/**
 * Port: hands out a new, unique CharacterId. Callers generate the id before dispatching
 * AddCharacter, because commands return nothing, and then read the campaign for the Character by
 * that id.
 */
interface CharacterIdGenerator
{
    public function generate(): CharacterId;
}
