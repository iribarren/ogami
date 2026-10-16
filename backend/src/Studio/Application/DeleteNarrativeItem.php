<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Command;
use App\Studio\Domain\GameSystem\NarrativeSection;

/**
 * Deletes a tracker, tag, fact slot, Scene Type or flow of the owner's GameSystem draft.
 * $expectedVersion is the draft version the client read.
 */
final readonly class DeleteNarrativeItem implements Command
{
    public function __construct(
        public string $ownerId,
        public string $key,
        public int $expectedVersion,
        public NarrativeSection $section,
        public string $itemKey,
    ) {
    }
}
