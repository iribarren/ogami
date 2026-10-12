<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Facts;

/**
 * What a Fact link points at: an NPC, a Thread or another Campaign fact of the same campaign.
 */
enum FactLinkKind: string
{
    case Npc = 'npc';
    case Thread = 'thread';
    case Fact = 'fact';
}
