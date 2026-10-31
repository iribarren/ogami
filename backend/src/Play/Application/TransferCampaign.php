<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Starts a new campaign from one of the player's campaigns (ADR 0046): on the latest release of its
 * GameSystem, to play along one of its Flows or, with no Flow key, freely, carrying the NPCs, the
 * Threads, the Places, the Campaign facts and the chosen Characters. The caller generates the new id with
 * CampaignIdGenerator, so it can read the campaign back once the command is handled.
 */
final readonly class TransferCampaign implements Command
{
    /**
     * @param list<string> $characterIds the Characters of the source campaign to carry
     */
    public function __construct(
        public string $campaignId,
        public string $userId,
        public string $newCampaignId,
        public string $name,
        public ?string $flowKey,
        public array $characterIds,
    ) {
    }
}
