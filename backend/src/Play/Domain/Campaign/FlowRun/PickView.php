<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Play\Domain\GameSystem\Flow\PickMode;
use App\Play\Domain\GameSystem\Tag;

/**
 * What the player sees of a pick step: the kind of item, how it is picked, how many, the release
 * Tags the items must carry and the candidates now (open items of the kind carrying all the Tags,
 * in list order; none when nothing matches).
 */
final readonly class PickView
{
    /**
     * @param list<Tag>           $tags       in the step's order
     * @param list<PickCandidate> $candidates
     */
    public function __construct(
        public ItemKind $of,
        public PickMode $mode,
        public int $min,
        public int $max,
        public array $tags,
        public array $candidates,
    ) {
    }
}
