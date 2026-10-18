<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Campaign\Upgrade\TrackerChange;
use OpenApi\Attributes as OA;

/**
 * How the upgrade changes the campaign's value of one Tracker.
 */
#[OA\Schema(required: ['trackerKey', 'name', 'kind', 'before', 'after'])]
final readonly class UpgradeTrackerChangeResponse
{
    private function __construct(
        #[OA\Property(example: 'heat')]
        public string $trackerKey,
        #[OA\Property(example: 'Heat')]
        public string $name,
        #[OA\Property(description: '"removed" (the Tracker is gone) or "clamped" (the value moved inside the new bounds).', enum: ['removed', 'clamped'])]
        public string $kind,
        #[OA\Property(example: 9)]
        public int $before,
        #[OA\Property(description: 'The value after the upgrade; null for a removed Tracker.', example: 6, nullable: true)]
        public ?int $after,
    ) {
    }

    public static function fromChange(TrackerChange $change): self
    {
        return new self($change->trackerKey, $change->name, $change->kind->value, $change->before, $change->after);
    }
}
