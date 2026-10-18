<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Campaign\Upgrade\ReleaseUpgradePlan;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * What upgrading the campaign to the latest release of its GameSystem changes (ADR 0035). Nothing
 * else changes; confirming sends its version and fingerprint back.
 */
#[OA\Schema(required: ['from', 'to', 'characters', 'npcs', 'threads', 'trackers', 'facts', 'flow', 'fingerprint'])]
final readonly class ReleaseUpgradePlanResponse
{
    /**
     * @param list<UpgradeCharacterResponse>     $characters
     * @param list<UpgradeDroppedTagsResponse>   $npcs
     * @param list<UpgradeDroppedTagsResponse>   $threads
     * @param list<UpgradeTrackerChangeResponse> $trackers
     * @param list<UpgradeUnslottedFactResponse> $facts
     */
    private function __construct(
        #[OA\Property(ref: new Model(type: UpgradeReleaseResponse::class), description: 'The release the campaign plays.')]
        public UpgradeReleaseResponse $from,
        #[OA\Property(ref: new Model(type: UpgradeReleaseResponse::class), description: 'The release the campaign moves to.')]
        public UpgradeReleaseResponse $to,
        #[OA\Property(description: 'The Characters that change, in campaign order.', type: 'array', items: new OA\Items(ref: new Model(type: UpgradeCharacterResponse::class)))]
        public array $characters,
        #[OA\Property(description: 'The NPCs that lose release Tags, in creation order.', type: 'array', items: new OA\Items(ref: new Model(type: UpgradeDroppedTagsResponse::class)))]
        public array $npcs,
        #[OA\Property(description: 'The Threads that lose release Tags, in creation order.', type: 'array', items: new OA\Items(ref: new Model(type: UpgradeDroppedTagsResponse::class)))]
        public array $threads,
        #[OA\Property(description: 'The Tracker values that change, by Tracker key.', type: 'array', items: new OA\Items(ref: new Model(type: UpgradeTrackerChangeResponse::class)))]
        public array $trackers,
        #[OA\Property(description: 'The slot facts that become free facts, in creation order.', type: 'array', items: new OA\Items(ref: new Model(type: UpgradeUnslottedFactResponse::class)))]
        public array $facts,
        #[OA\Property(ref: new Model(type: UpgradeFlowChangeResponse::class), description: 'What happens to the guidance.')]
        public UpgradeFlowChangeResponse $flow,
        #[OA\Property(description: 'Stands for exactly this change (SHA-256 of the plan). The upgrade is refused when the campaign or the releases changed since, so the confirmed change is the previewed one.', example: '9f2c4e0a7b1d3c5e8f6a0b2d4c6e8f1a3b5d7c9e0f2a4b6c8d0e1f3a5b7c9d1e')]
        public string $fingerprint,
    ) {
    }

    public static function fromPlan(ReleaseUpgradePlan $plan): self
    {
        return new self(
            UpgradeReleaseResponse::fromRelease($plan->from),
            UpgradeReleaseResponse::fromRelease($plan->to),
            array_map(UpgradeCharacterResponse::fromUpgrade(...), $plan->characters),
            array_map(UpgradeDroppedTagsResponse::fromDropped(...), $plan->npcs),
            array_map(UpgradeDroppedTagsResponse::fromDropped(...), $plan->threads),
            array_map(UpgradeTrackerChangeResponse::fromChange(...), $plan->trackers),
            array_map(UpgradeUnslottedFactResponse::fromFact(...), $plan->facts),
            UpgradeFlowChangeResponse::fromChange($plan->flow),
            $plan->fingerprint(),
        );
    }
}
