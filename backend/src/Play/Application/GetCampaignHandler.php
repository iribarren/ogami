<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\Characters\Character;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Facts\Fact;
use App\Play\Domain\Campaign\Facts\FactId;
use App\Play\Domain\Campaign\Facts\FactLink;
use App\Play\Domain\Campaign\Facts\FactLinkKind;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\ThreadId;
use App\Play\Domain\Campaign\Scene;
use App\Play\Domain\Campaign\Session;
use App\Play\Domain\GameSystem\Check\Check;
use App\Play\Domain\GameSystem\Check\ReferenceLabels;
use App\Play\Domain\GameSystem\FactSlot;
use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\SnapshotLikelihoodOracle;
use App\Play\Domain\GameSystem\Tag;
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
        $trackerValues = $campaign->trackerValuesOf($snapshot);
        $labels = ReferenceLabels::of($snapshot);

        return new CampaignView(
            $campaign->id()->toString(),
            $campaign->name(),
            $campaign->createdAt(),
            new PinnedReleaseView($pinned->gameSystemKey(), $pinned->gameSystemName(), $pinned->releaseVersion(), $snapshot->theme(), $snapshot->credits(), array_map(SheetFieldView::of(...), $snapshot->sheet()->fields()), $snapshot->sheet()->layout(), $snapshot->sheet()->budgets()),
            array_map(static fn (Session $session): SessionView => self::session($session, $snapshot, $campaign), $campaign->sessions()),
            $campaign->currentSession()?->number(),
            $campaign->currentScene()?->number(),
            $this->oracleTables($snapshot),
            array_map($this->likelihoodOracle(...), $snapshot->likelihoodOracles()),
            array_map(static fn (Tracker $tracker): TrackerView => self::tracker($tracker, $campaign), $snapshot->trackers()),
            $campaign->flowKey(),
            $campaign->flowRunView($snapshot),
            array_map(FlowSummaryView::of(...), $snapshot->flows()),
            array_map(SceneTypeSummaryView::of(...), $snapshot->sceneTypes()),
            array_map(static fn (Tag $tag): TagView => new TagView($tag->key, $tag->label, $tag->hint, [] === $tag->appliesTo ? null : array_map(static fn (ItemKind $kind): string => $kind->value, $tag->appliesTo)), $snapshot->tags()),
            array_map(static fn (Check $check): CheckView => CheckView::of($check, $labels), $snapshot->checks()),
            array_map(NpcView::of(...), $campaign->npcs()),
            array_map(ThreadView::of(...), $campaign->threads()),
            array_map(FactSlotView::of(...), $snapshot->factSlots()),
            array_map(static fn (Fact $fact): FactView => self::fact($fact, $campaign, $snapshot), $campaign->facts($snapshot)),
            array_map(static fn (Character $character): CharacterView => CharacterView::of($character, $snapshot->sheet(), $trackerValues), $campaign->characters()),
        );
    }

    /**
     * @throws \LogicException when the fact names a slot, NPC, Thread or fact the campaign or its pinned release does not hold (the stored data is corrupt)
     */
    private static function fact(Fact $fact, Campaign $campaign, GameSystemSnapshot $snapshot): FactView
    {
        $subject = $fact->subject() instanceof FactLink ? self::factLink($fact->subject(), $campaign, $snapshot) : null;
        $label = self::factLabel($fact, $snapshot);

        return new FactView(
            $fact->id()->toString(),
            $fact->slot(),
            $label,
            $fact->text(),
            $subject,
            $fact->text() ?? ($subject instanceof FactLinkView ? $subject->name : throw new \LogicException(\sprintf('Fact "%s" holds neither a text nor a subject.', $fact->id()->toString()))),
            array_map(static fn (FactLink $link): FactLinkView => self::factLink($link, $campaign, $snapshot), $fact->links()),
        );
    }

    private static function factLabel(Fact $fact, GameSystemSnapshot $snapshot): string
    {
        if ($fact->isFree()) {
            return $fact->label() ?? throw new \LogicException(\sprintf('Free fact "%s" has no label.', $fact->id()->toString()));
        }

        $slot = $snapshot->factSlot($fact->slot() ?? '');

        return $slot instanceof FactSlot ? $slot->label : throw new \LogicException(\sprintf('Fact "%s" fills slot "%s", which the pinned release does not declare.', $fact->id()->toString(), $fact->slot()));
    }

    private static function factLink(FactLink $link, Campaign $campaign, GameSystemSnapshot $snapshot): FactLinkView
    {
        $name = match ($link->kind) {
            FactLinkKind::Npc => $campaign->npc(NpcId::fromString($link->id))?->name(),
            FactLinkKind::Thread => $campaign->thread(ThreadId::fromString($link->id))?->name(),
            FactLinkKind::Fact => ($target = $campaign->fact(FactId::fromString($link->id))) instanceof Fact ? self::factLabel($target, $snapshot) : null,
        };

        return new FactLinkView($link->kind->value, $link->id, $name ?? throw new \LogicException(\sprintf('A fact links to %s "%s", which the campaign does not hold.', $link->kind->value, $link->id)));
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

    private static function session(Session $session, GameSystemSnapshot $snapshot, Campaign $campaign): SessionView
    {
        return new SessionView(
            $session->number(),
            $session->startedAt(),
            array_map(static fn (Scene $scene): SceneView => self::scene($scene, $snapshot, $campaign), $session->scenes()),
            $session->endedAt(),
            self::characters($session->party(), $campaign, \sprintf('The party of session %d', $session->number())),
        );
    }

    /**
     * @throws \LogicException when the cast names an NPC or a Character the campaign does not hold (they are never deleted, so the stored data is corrupt)
     */
    private static function scene(Scene $scene, GameSystemSnapshot $snapshot, Campaign $campaign): SceneView
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
            array_map(static fn (NpcId $id): CastMemberView => new CastMemberView($id->toString(), $campaign->npc($id)?->name() ?? throw new \LogicException(\sprintf('Scene %d casts NPC "%s", which the campaign does not hold.', $scene->number(), $id->toString()))), $scene->cast()),
            self::characters($scene->characters(), $campaign, \sprintf('Scene %d', $scene->number())),
        );
    }

    /**
     * @param list<CharacterId> $ids
     *
     * @return list<CastMemberView> the Characters, named as the campaign names them now
     *
     * @throws \LogicException when the campaign does not hold one
     */
    private static function characters(array $ids, Campaign $campaign, string $holder): array
    {
        return array_map(static fn (CharacterId $id): CastMemberView => new CastMemberView($id->toString(), $campaign->character($id)?->name() ?? throw new \LogicException(\sprintf('%s names Character "%s", which the campaign does not hold.', $holder, $id->toString()))), $ids);
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
