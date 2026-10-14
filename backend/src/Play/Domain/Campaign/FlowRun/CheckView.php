<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

/**
 * What the player sees of a check step beyond the step itself: the Check difficulty the step fixes
 * (and whether it fixes one) and the Characters who can roll it.
 */
final readonly class CheckView
{
    /**
     * @param ?int             $difficulty      the step's fixed difficulty as it stands now; null when the player sets it (or a formula of the step cannot be evaluated: rolling it is refused)
     * @param bool             $difficultyFixed whether the step fixes the difficulty (a literal, a Tracker or a Formula), even when it cannot be evaluated now
     * @param list<PickedItem> $characters      the Characters in the Scene (in the session party outside one), in the order they joined
     */
    public function __construct(
        public ?int $difficulty,
        public bool $difficultyFixed,
        public array $characters,
    ) {
    }
}
