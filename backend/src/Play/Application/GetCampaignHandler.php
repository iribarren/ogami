<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\Scene;
use App\Play\Domain\Campaign\Session;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\SnapshotLikelihoodOracle;
use App\Play\Domain\GameSystem\Tracker;
use App\Play\Domain\GameSystem\TrackerLevel;
use App\Play\Domain\GameSystem\UnknownFlow;
use App\Randomness\Domain\Oracle\LikelihoodChaos;
use App\Randomness\Domain\Oracle\LikelihoodLevel;
use App\Shared\Application\Bus\QueryHandler;

final readonly class GetCampaignHandler implements QueryHandler
{
    public function __construct(
        private OwnedCampaigns $ownedCampaigns,
        private PublishedGameSystemReleases $releases,
    ) {
    }

    /**
     * @throws CampaignNotFound
     * @throws GameSystemReleaseNotFound when the pinned release can no longer be read
     * @throws UnknownFlow               when the pinned release has no Flow with the campaign's key
     */
    public function __invoke(GetCampaign $query): CampaignView
    {
        $campaign = $this->ownedCampaigns->get($query->campaignId, $query->userId);
        $pinned = $campaign->pinnedRelease();
        // Oracles come from the pinned release, never the latest one (ADR 0014).
        $snapshot = $this->releases->get($pinned->gameSystemKey(), $pinned->releaseVersion());

        return new CampaignView(
            $campaign->id()->toString(),
            $campaign->name(),
            $campaign->createdAt(),
            new PinnedReleaseView($pinned->gameSystemKey(), $pinned->gameSystemName(), $pinned->releaseVersion()),
            array_map(static fn (Session $session): SessionView => self::session($session, $snapshot), $campaign->sessions()),
            $campaign->currentSession()?->number(),
            $campaign->currentScene()?->number(),
            $this->oracleTables($snapshot),
            array_map($this->likelihoodOracle(...), $snapshot->likelihoodOracles()),
            array_map(static fn (Tracker $tracker): TrackerView => self::tracker($tracker, $campaign), $snapshot->trackers()),
            $campaign->flowKey(),
            $campaign->flowRunView($snapshot),
            array_map(FlowSummaryView::of(...), $snapshot->flows()),
            array_map(SceneTypeSummaryView::of(...), $snapshot->sceneTypes()),
        );
    }

    private static function tracker(Tracker $tracker, Campaign $campaign): TrackerView
    {
        $value = $campaign->trackerValue($tracker);

        return new TrackerView(
            $tracker->key,
            $tracker->name,
            $tracker->kind->value,
            $tracker->hint,
            $tracker->min,
            $tracker->max,
            $tracker->segments(),
            array_map(static fn (TrackerLevel $level): TrackerLevelView => new TrackerLevelView($level->upTo, $level->label), $tracker->levels),
            $value,
            $tracker->levelAt($value)?->label,
        );
    }

    private static function session(Session $session, GameSystemSnapshot $snapshot): SessionView
    {
        return new SessionView(
            $session->number(),
            $session->startedAt(),
            array_map(static fn (Scene $scene): SceneView => self::scene($scene, $snapshot), $session->scenes()),
            $session->endedAt(),
        );
    }

    private static function scene(Scene $scene, GameSystemSnapshot $snapshot): SceneView
    {
        $sceneType = $scene->sceneType();

        return new SceneView(
            $scene->number(),
            $scene->title(),
            $scene->startedAt(),
            $scene->kind()->value,
            $sceneType,
            null === $sceneType ? null : $snapshot->sceneType($sceneType)?->name,
            $scene->hook()?->value,
        );
    }

    /**
     * @return list<OracleTableView>
     */
    private function oracleTables(GameSystemSnapshot $snapshot): array
    {
        $views = [];
        foreach ($snapshot->oracleTableNames() as $key => $name) {
            $views[] = new OracleTableView($key, $name);
        }

        return $views;
    }

    private function likelihoodOracle(SnapshotLikelihoodOracle $oracle): LikelihoodOracleView
    {
        $chaos = $oracle->oracle()->chaos();

        return new LikelihoodOracleView(
            $oracle->key(),
            $oracle->name(),
            array_map(
                static fn (LikelihoodLevel $level): LikelihoodLevelView => new LikelihoodLevelView($level->key(), $level->label()),
                $oracle->oracle()->levels(),
            ),
            $chaos instanceof LikelihoodChaos ? new LikelihoodChaosView($chaos->min(), $chaos->max(), $chaos->neutral()) : null,
            $oracle->chaosTracker(),
        );
    }
}
