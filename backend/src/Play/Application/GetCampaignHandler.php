<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\Scene;
use App\Play\Domain\Campaign\Session;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\SnapshotLikelihoodOracle;
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
            array_map($this->session(...), $campaign->sessions()),
            $campaign->currentSession()?->number(),
            $campaign->currentScene()?->number(),
            $this->oracleTables($snapshot),
            array_map($this->likelihoodOracle(...), $snapshot->likelihoodOracles()),
        );
    }

    private function session(Session $session): SessionView
    {
        return new SessionView(
            $session->number(),
            $session->startedAt(),
            array_map(
                static fn (Scene $scene): SceneView => new SceneView($scene->number(), $scene->title(), $scene->startedAt()),
                $session->scenes(),
            ),
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
        );
    }
}
