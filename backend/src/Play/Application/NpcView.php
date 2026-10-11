<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\Lists\Npc;

/**
 * An NPC of a campaign: its Agenda, release Tag keys, free tags, weight and status ("open" or "closed").
 */
final readonly class NpcView
{
    /**
     * @param list<string> $tags     release Tag keys
     * @param list<string> $freeTags
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $agenda,
        public array $tags,
        public array $freeTags,
        public int $weight,
        public string $status,
    ) {
    }

    public static function of(Npc $npc): self
    {
        return new self($npc->id()->toString(), $npc->name(), $npc->agenda(), $npc->tags(), $npc->freeTags(), $npc->weight(), $npc->status()->value);
    }
}
