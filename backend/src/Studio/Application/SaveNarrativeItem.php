<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Command;
use App\Studio\Domain\GameSystem\NarrativeSection;

/**
 * Creates a tracker, tag, fact slot, Scene Type or flow in the owner's GameSystem draft, or
 * replaces the one with $originalKey (renaming it when $item has another key). $expectedVersion is
 * the draft version the client read.
 */
final readonly class SaveNarrativeItem implements Command
{
    /**
     * @param string|null  $originalKey the key of the item to replace; null creates a new one
     * @param array<mixed> $item        the item as in a release document
     */
    public function __construct(
        public string $ownerId,
        public string $key,
        public int $expectedVersion,
        public NarrativeSection $section,
        public ?string $originalKey,
        public array $item,
    ) {
    }
}
