<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\GameSystem\Flow\PickStep;
use App\Randomness\Domain\RandomNumberGenerator;

/**
 * The random draw of a pick step: the smaller of its maximum and the candidates, each drawn with a
 * chance proportional to its weight and none twice. Fewer candidates than the minimum draw nothing.
 */
final readonly class PickDraw
{
    public function __construct(
        private RandomNumberGenerator $random,
    ) {
    }

    /**
     * @return list<PickedItem> in the order drawn
     */
    public function draw(PickStep $step, PickCandidates $candidates): array
    {
        $remaining = $candidates->items;
        if (\count($remaining) < $step->min) {
            return [];
        }

        $drawn = [];
        for ($left = min($step->max, \count($remaining)); $left > 0; --$left) {
            $roll = $this->random->between(1, array_sum(array_map(static fn (PickCandidate $item): int => $item->weight, $remaining)));
            foreach ($remaining as $index => $candidate) {
                $roll -= $candidate->weight;
                if ($roll <= 0) {
                    $drawn[] = new PickedItem($candidate->id, $candidate->name);
                    array_splice($remaining, $index, 1);

                    break;
                }
            }
        }

        return $drawn;
    }
}
