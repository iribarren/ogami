<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Upgrade;

/**
 * One change to a stored Tracker value; after is null for a removed one.
 */
final readonly class TrackerChange
{
    public function __construct(
        public string $trackerKey,
        public string $name,
        public TrackerChangeKind $kind,
        public int $before,
        public ?int $after,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['trackerKey' => $this->trackerKey, 'name' => $this->name, 'kind' => $this->kind->value, 'before' => $this->before, 'after' => $this->after];
    }
}
