<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\GameSystem\Credits;

/**
 * The latest published release of a GameSystem, as Play offers it when a campaign is created, with
 * the Flows a campaign can play and the credits it carries (ListGameSystems reads them from the release; the port lists none).
 */
final readonly class GameSystemSummary
{
    /**
     * @param list<FlowSummaryView> $flows in definition order
     */
    public function __construct(
        public string $gameSystemKey,
        public string $name,
        public ?string $description,
        public int $version,
        public array $flows = [],
        public ?Credits $credits = null,
    ) {
    }
}
