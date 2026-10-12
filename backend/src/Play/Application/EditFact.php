<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Edits a Campaign fact of one of the player's campaigns. A free fact takes a new label and text
 * (both required) and new links; a slot fact takes only new links (a label or text is refused).
 * The links replace the fact's links.
 */
final readonly class EditFact implements Command
{
    /**
     * @param list<array{kind: string, id: string}> $links what the fact links to; the kind is "npc", "thread" or "fact"
     */
    public function __construct(
        public string $campaignId,
        public string $userId,
        public string $factId,
        public ?string $label,
        public ?string $text,
        public array $links,
    ) {
    }
}
