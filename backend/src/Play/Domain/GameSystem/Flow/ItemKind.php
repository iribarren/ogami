<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

/**
 * What a pick step or a count condition works on: the Campaign's NPCs, Threads or Characters.
 */
enum ItemKind: string
{
    case Npc = 'npc';
    case Thread = 'thread';
    case Character = 'character';
}
