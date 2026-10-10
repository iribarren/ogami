<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Creates a campaign for a player, pinned to the latest release of the chosen GameSystem
 * (ADR 0014), to play along one of its Flows or, with no Flow key, freely ("Play freely"; the
 * release's default Flow is never applied here). The caller generates the id with
 * CampaignIdGenerator, so it can read the campaign back once the command is handled.
 */
final readonly class CreateCampaign implements Command
{
    public function __construct(
        public string $campaignId,
        public string $ownerId,
        public string $name,
        public string $gameSystemKey,
        public ?string $flowKey,
    ) {
    }
}
