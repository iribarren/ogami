<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\GameSystem\Credits;
use App\Play\Domain\GameSystem\Sheet\PointBudget;
use App\Play\Domain\GameSystem\Sheet\SheetLayout;

/**
 * The GameSystem release a campaign is pinned to; theme names the curated Theme the release asks
 * for, null for the default app theme; credits are the notice, license and link the release carries, null without;
 * sheet lists the fields Characters fill in (none for a release without a sheet); sheetLayout is how the
 * release groups them (ADR 0031), null when it declares no groups; pointBudgets are the points a player may spend on number
 * fields when creating a Character (ADR 0040, 0043), all of them in order, a legacy single budget as the key "points".
 */
final readonly class PinnedReleaseView
{
    /**
     * @param list<SheetFieldView> $sheet        in layout order
     * @param list<PointBudget>    $pointBudgets in order
     */
    public function __construct(
        public string $gameSystemKey,
        public string $gameSystemName,
        public int $version,
        public ?string $theme,
        public ?Credits $credits = null,
        public array $sheet = [],
        public ?SheetLayout $sheetLayout = null,
        public array $pointBudgets = [],
    ) {
    }
}
