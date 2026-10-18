<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Upgrade;

/**
 * What an upgrade changes in one Character: its value changes and the release Tags it loses.
 */
final readonly class CharacterUpgrade
{
    /**
     * @param list<FieldChange> $changes     the new sheet's fields in order, then the removed ones in the old order
     * @param list<string>      $droppedTags release Tag keys no longer in the release, as stored
     */
    public function __construct(
        public string $characterId,
        public string $name,
        public array $changes,
        public array $droppedTags,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'characterId' => $this->characterId,
            'name' => $this->name,
            'changes' => array_map(static fn (FieldChange $change): array => $change->toArray(), $this->changes),
            'droppedTags' => $this->droppedTags,
        ];
    }
}
