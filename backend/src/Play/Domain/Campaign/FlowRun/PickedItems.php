<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\GameSystem\Flow\ItemKind;

/**
 * What "{picked}" stands for: the NPCs or the Threads most recently picked in the current Scene,
 * by id (their names are read when rendered, so a rename shows). The kind stays with the ids so
 * Effects can act on them.
 */
final readonly class PickedItems
{
    public const int MAX_ITEMS = 10;

    /**
     * @param list<string> $ids
     *
     * @throws \InvalidArgumentException unless there are 1 to 10 distinct, non-blank ids
     */
    public function __construct(
        public ItemKind $kind,
        public array $ids,
    ) {
        if ([] === $ids || \count($ids) > self::MAX_ITEMS) {
            throw new \InvalidArgumentException(\sprintf('Picked items hold 1 to %d ids, got %d.', self::MAX_ITEMS, \count($ids)));
        }

        if (\count(array_unique($ids)) !== \count($ids) || \in_array('', array_map(trim(...), $ids), true)) {
            throw new \InvalidArgumentException('Picked item ids must be distinct and not blank.');
        }
    }
}
