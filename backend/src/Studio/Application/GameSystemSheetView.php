<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * The sheet of a GameSystem draft as stored, with the draft version a client sends back as the
 * expected version of its next sheet command. The draft may be invalid, so what is not a field with
 * a string key is left out. A schema version 1 draft has no sheet fields.
 */
final readonly class GameSystemSheetView
{
    /**
     * @param int                           $columns            the sheet's columns of groups, 1 when not set
     * @param list<SheetGroupView>|null     $groups             the declared groups in order; null when the sheet declares none
     * @param list<array<array-key, mixed>> $fields             the fields as stored, in order
     * @param list<SheetTrackerView>        $trackers           the draft's trackers, which derived fields may read as {tracker:key}
     * @param list<string>                  $publishedFieldKeys the field keys of the latest release of this GameSystem; none when it has no release
     * @param list<SheetPointBudgetView>    $pointBudgets       the point budgets in order, a legacy single budget as the key "points"; none without
     */
    public function __construct(
        public int $version,
        public int $schemaVersion,
        public int $columns,
        public ?array $groups,
        public array $fields,
        public array $trackers,
        public array $publishedFieldKeys,
        public array $pointBudgets = [],
    ) {
    }
}
