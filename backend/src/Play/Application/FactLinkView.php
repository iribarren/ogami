<?php

declare(strict_types=1);

namespace App\Play\Application;

/**
 * What a Campaign fact points at: an NPC, a Thread ("npc", "thread") or another fact ("fact"),
 * with the name it has now (a fact's name is its label).
 */
final readonly class FactLinkView
{
    public function __construct(
        public string $kind,
        public string $id,
        public string $name,
    ) {
    }
}
