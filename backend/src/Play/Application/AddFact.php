<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Adds a free Campaign fact to one of the player's campaigns. The caller generates the id (see FactIdGenerator).
 */
final readonly class AddFact implements Command
{
    /**
     * @param list<array{kind: string, id: string}> $links what the fact links to; the kind is "npc", "thread" or "fact"
     */
    public function __construct(
        public string $campaignId,
        public string $userId,
        public string $factId,
        public string $label,
        public string $text,
        public array $links,
    ) {
    }
}
