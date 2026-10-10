<?php

declare(strict_types=1);

namespace App\Play\Application;

/**
 * One campaign as its owner plays it: the pinned release, sessions with their scenes, the current
 * session and scene, the oracles of the pinned release, its Trackers with the campaign's values,
 * the Flow played (null when played freely), and the release's Flows and Scene Types.
 */
final readonly class CampaignView
{
    /**
     * @param list<SessionView>          $sessions          in number order
     * @param list<OracleTableView>      $oracleTables      in definition order
     * @param list<LikelihoodOracleView> $likelihoodOracles in definition order
     * @param list<TrackerView>          $trackers          in definition order
     * @param list<FlowSummaryView>      $flows             in definition order
     * @param list<SceneTypeSummaryView> $sceneTypes        in definition order
     */
    public function __construct(
        public string $id,
        public string $name,
        public \DateTimeImmutable $createdAt,
        public PinnedReleaseView $pinnedRelease,
        public array $sessions,
        public ?int $currentSessionNumber,
        public ?int $currentSceneNumber,
        public array $oracleTables,
        public array $likelihoodOracles,
        public array $trackers,
        public ?string $flowKey,
        public array $flows,
        public array $sceneTypes,
    ) {
    }
}
