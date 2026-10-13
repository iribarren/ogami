<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\FlowRun\FlowRunView;

/**
 * One campaign as its owner plays it: the pinned release, sessions with their scenes, the current
 * session and scene, the oracles of the pinned release, its Trackers with the campaign's values,
 * the Flow played (null when played freely) with the FlowRun guiding it, the release's Flows and
 * Scene Types, Tags and Checks, and the campaign's NPCs, Threads, Fact slots, facts and Characters.
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
     * @param list<TagView>              $tags              of the pinned release, in definition order
     * @param list<CheckView>            $checks            of the pinned release, in definition order
     * @param list<NpcView>              $npcs              open ones first, then closed ones, each in creation order
     * @param list<ThreadView>           $threads           open ones first, then closed ones, each in creation order
     * @param list<FactSlotView>         $factSlots         of the pinned release, in definition order
     * @param list<FactView>             $facts             the slot facts first, in slot order, then the free facts in creation order
     * @param list<CharacterView>        $characters        in creation order
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
        public ?FlowRunView $flowRun,
        public array $flows,
        public array $sceneTypes,
        public array $tags,
        public array $checks,
        public array $npcs,
        public array $threads,
        public array $factSlots,
        public array $facts,
        public array $characters,
    ) {
    }
}
