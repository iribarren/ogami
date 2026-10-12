<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\GameSystem\Flow\PickStep;

/**
 * The candidates of a pick step, in list order: the open items of its kind carrying all its release
 * Tags (free tags never match). Validates what is picked from them.
 */
final readonly class PickCandidates
{
    /**
     * @param list<PickCandidate> $items
     */
    public function __construct(
        public array $items,
    ) {
    }

    /**
     * The items picked by these ids, all or nothing. With enough candidates for the minimum the ids
     * must be distinct candidates, between the step's minimum and maximum; with fewer, nothing can
     * be picked and only an empty list is valid.
     *
     * @param list<string> $ids
     *
     * @return list<PickedItem> in the order of the ids, named as the candidates are now
     *
     * @throws InvalidStepResult
     */
    public function select(PickStep $step, array $ids): array
    {
        if (\count($this->items) < $step->min) {
            return [] === $ids ? [] : throw InvalidStepResult::tooFewCandidates($step->key, $step->min, \count($this->items));
        }

        $picked = [];
        foreach ($ids as $id) {
            $candidate = array_find($this->items, static fn (PickCandidate $item): bool => $item->id === $id) ?? throw InvalidStepResult::notACandidate($step->key, $id);
            if (isset($picked[$id])) {
                throw InvalidStepResult::repeatedPick($step->key, $id);
            }

            $picked[$id] = new PickedItem($candidate->id, $candidate->name);
        }

        if (\count($picked) < $step->min || \count($picked) > $step->max) {
            throw InvalidStepResult::pickCount($step->key, $step->min, $step->max, \count($picked));
        }

        return array_values($picked);
    }
}
