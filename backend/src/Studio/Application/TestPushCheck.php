<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Query;

/**
 * Pushes the sandbox roll of a pool check of the owner's saved GameSystem draft, statelessly: the
 * client sends the faces of the roll it holds with the samples it rolled for, and gets the roll
 * pushed. Nothing is recorded and the push cost is not applied. Like a test roll, the values are what
 * the client sent, unchecked, so the handler refuses what is not an integer within its range.
 *
 * @implements Query<CheckTestRollView>
 */
final readonly class TestPushCheck implements Query
{
    /**
     * @param mixed $sheet       the sample of each number and track field by key
     * @param mixed $trackers    the sample of each tracker by key
     * @param mixed $difficulty  the Check difficulty of the roll, null for the check's default
     * @param mixed $situational the situational modifier of the roll, null for 0
     * @param mixed $faces       the face of each die of the roll, in roll order
     */
    public function __construct(
        public string $ownerId,
        public string $key,
        public string $check,
        public mixed $sheet,
        public mixed $trackers,
        public mixed $difficulty,
        public mixed $situational,
        public mixed $faces,
    ) {
    }
}
