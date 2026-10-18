<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Upgrade;

/**
 * The release Tags an NPC or a Thread loses in an upgrade.
 */
final readonly class DroppedTags
{
    /**
     * @param list<string> $tags release Tag keys no longer in the release, as stored
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $tags,
    ) {
    }

    /**
     * @return array{id: string, name: string, tags: list<string>}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'tags' => $this->tags];
    }
}
