<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem;

enum FactSlotType: string
{
    case Text = 'text';
    case Npc = 'npc';
    case Thread = 'thread';
}
