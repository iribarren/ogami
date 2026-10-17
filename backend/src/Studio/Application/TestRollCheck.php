<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Query;

/**
 * Rolls a check of the owner's saved GameSystem draft against sample values, without recording
 * anything: a test roll never changes the draft. The samples are what the client sent, unchecked,
 * so the handler refuses what is not an integer within its field's range.
 *
 * @implements Query<CheckTestRollView>
 */
final readonly class TestRollCheck implements Query
{
    /**
     * @param mixed $sheet       the sample of each number and track field by key
     * @param mixed $trackers    the sample of each tracker by key
     * @param mixed $difficulty  the Check difficulty, null for the check's default
     * @param mixed $situational the situational modifier, null for 0
     */
    public function __construct(
        public string $ownerId,
        public string $key,
        public string $check,
        public mixed $sheet,
        public mixed $trackers,
        public mixed $difficulty,
        public mixed $situational,
    ) {
    }
}
