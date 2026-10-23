<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Check;

use App\Formula\Domain\Reference;
use App\Formula\Domain\ReferenceKind;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Play\Domain\GameSystem\Tracker;

/**
 * The names players know the references of a check's modifier by: a sheet field's label ("Hot"), a
 * track's label plus " max" for its maximum, a Tracker's name. A key the release does not name reads as itself.
 */
final readonly class ReferenceLabels
{
    /**
     * @param array<string, string> $trackerNames the name of every Tracker of the release by key
     */
    public function __construct(
        private SheetTemplate $sheet,
        private array $trackerNames = [],
    ) {
    }

    public static function of(GameSystemSnapshot $release): self
    {
        return new self($release->sheet(), array_column(array_map(static fn (Tracker $tracker): array => [$tracker->key, $tracker->name], $release->trackers()), 1, 0));
    }

    public function label(Reference $reference): string
    {
        if (ReferenceKind::Tracker === $reference->kind) {
            return $this->trackerNames[$reference->key] ?? $reference->key;
        }

        $label = $this->sheet->field($reference->key)->label ?? $reference->key;

        return ReferenceKind::TrackMax === $reference->kind ? $label.' max' : $label;
    }
}
