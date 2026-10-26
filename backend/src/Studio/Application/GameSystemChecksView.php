<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * The checks of a GameSystem draft as stored, with the draft version a client sends back as the
 * expected version of its next check command. The draft may be invalid, so what is not a check with
 * a string key is left out. A schema version 1 draft has no checks.
 */
final readonly class GameSystemChecksView
{
    /**
     * @param list<array<array-key, mixed>>  $checks     the checks as stored, in order
     * @param list<CheckFieldView>           $fields     the sheet fields a formula may read, in order
     * @param list<CheckTrackerView>         $trackers   the draft's trackers
     * @param list<CheckConditionsFieldView> $conditions the draft's conditions fields with their options, which a push cost marks or clears
     * @param array<string, list<string>>    $usages     the paths of the check steps that name each check, by check key; empty for an unused check
     */
    public function __construct(
        public int $version,
        public int $schemaVersion,
        public array $checks,
        public array $fields,
        public array $trackers,
        public array $conditions,
        public array $usages,
    ) {
    }
}
