<?php

declare(strict_types=1);

namespace App\Tests\Support\Play;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignAlreadyExists;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\Characters\Character;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Facts\Fact;
use App\Play\Domain\Campaign\Facts\FactId;
use App\Play\Domain\Campaign\Facts\FactLink;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunEvent;
use App\Play\Domain\Campaign\FlowRun\FlowRunHistoryEntry;
use App\Play\Domain\Campaign\FlowRun\FlowRunStage;
use App\Play\Domain\Campaign\FlowRun\FlowRunStatus;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\Campaign\Hook;
use App\Play\Domain\Campaign\Lists\ItemStatus;
use App\Play\Domain\Campaign\Lists\Npc;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\Place;
use App\Play\Domain\Campaign\Lists\PlaceId;
use App\Play\Domain\Campaign\Lists\Thread;
use App\Play\Domain\Campaign\Lists\ThreadId;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\Campaign\Scene;
use App\Play\Domain\Campaign\SceneKind;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Infrastructure\GameSystem\GameSystemReleaseTranslator;
use PHPUnit\Framework\Attributes\Test;

/**
 * The CampaignRepository contract, shared by the in-memory double and the Doctrine adapter so the
 * double cannot drift from the real thing. Ids are UUIDs because the database stores them as such.
 *
 * The using test class provides the repository, forgetLoaded(): what a new request would see (the
 * Doctrine test clears its entity managers, the in-memory one has nothing to forget), and
 * campaignsElsewhere(): the same campaigns as another request working at the same time sees them.
 */
trait CampaignRepositoryContract
{
    private const string OWNER = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f5001';
    private const string OTHER_OWNER = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f5002';
    private const string UNKNOWN_OWNER = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f5003';

    abstract protected function campaigns(): CampaignRepository;

    abstract protected function forgetLoaded(): void;

    abstract protected function campaignsElsewhere(): CampaignRepository;

    #[Test]
    public function itKeepsEveryFieldOfACampaignWithItsSessionsAndScenes(): void
    {
        $campaign = Campaign::create(
            CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057'),
            self::OWNER,
            'The lost mine',
            PinnedRelease::of('free-journal', 3, 'Free journal'),
            new \DateTimeImmutable('2026-10-06T10:00:00+02:00'),
        );
        $campaign->startSession(new \DateTimeImmutable('2026-10-06T10:05:00.123456+02:00'));
        $campaign->startScene('At the gate', new \DateTimeImmutable('2026-10-06T10:06:00+02:00'));
        $campaign->startScene('In the mine', new \DateTimeImmutable('2026-10-06T10:30:00+00:00'));
        $campaign->endSession(new \DateTimeImmutable('2026-10-06T11:00:00+00:00'));
        $campaign->startSession(new \DateTimeImmutable('2026-10-07T18:00:00-05:00'));

        $this->campaigns()->add($campaign);
        $this->forgetLoaded();
        $loaded = $this->campaigns()->ofId(CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057'));

        self::assertNotNull($loaded);
        self::assertSame('01890a5d-ac96-774b-bcce-b302099a8057', $loaded->id()->toString());
        self::assertSame(self::OWNER, $loaded->ownerId());
        self::assertSame('The lost mine', $loaded->name());
        self::assertTrue($loaded->pinnedRelease()->equals(PinnedRelease::of('free-journal', 3, 'Free journal')));
        self::assertSame('Free journal', $loaded->pinnedRelease()->gameSystemName());
        self::assertSame(new \DateTimeImmutable('2026-10-06T08:00:00+00:00')->getTimestamp(), $loaded->createdAt()->getTimestamp());

        $sessions = $loaded->sessions();
        self::assertCount(2, $sessions);
        self::assertSame(1, $sessions[0]->number());
        self::assertEquals(new \DateTimeImmutable('2026-10-06T10:05:00.123456+02:00'), $sessions[0]->startedAt());
        self::assertSame('+02:00', $sessions[0]->startedAt()->format('P'));
        self::assertSame([[1, 'At the gate'], [2, 'In the mine']], array_map(static fn ($scene): array => [$scene->number(), $scene->title()], $sessions[0]->scenes()));
        self::assertEquals(new \DateTimeImmutable('2026-10-06T10:30:00+00:00'), $sessions[0]->scenes()[1]->startedAt());
        self::assertSame(2, $sessions[1]->number());
        self::assertSame([], $sessions[1]->scenes());
        self::assertSame(2, $loaded->currentSession()?->number());
        self::assertNull($loaded->currentScene());
        self::assertEquals($campaign, $loaded);
    }

    #[Test]
    public function itKeepsTheKindSceneTypeAndHookOfEveryScene(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $sceneTypes = Snapshots::withSceneTypes('heist', 'Heist', 1);
        $campaign = Campaign::create($id, self::OWNER, 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-10T09:00:00+00:00'));
        $campaign->startSession(new \DateTimeImmutable('2026-10-10T09:00:00+00:00'));
        $campaign->startHookScene(Hook::SessionOpening, 'Session 1 begins', new \DateTimeImmutable('2026-10-10T09:01:00+00:00'));
        $campaign->startScene(null, new \DateTimeImmutable('2026-10-10T09:02:00+00:00'), $sceneTypes->sceneType('legwork'));
        $campaign->startScene('A quiet drink', new \DateTimeImmutable('2026-10-10T09:03:00+00:00'));
        $campaign->switchSceneType($sceneTypes->sceneType('firefight') ?? throw new \LogicException('No firefight.'));

        $this->campaigns()->add($campaign);
        $this->forgetLoaded();
        $loaded = $this->campaigns()->ofId($id);

        self::assertNotNull($loaded);
        self::assertSame([
            [1, 'Session 1 begins', SceneKind::Hook, null, Hook::SessionOpening],
            [2, 'Legwork 1', SceneKind::Scene, 'legwork', null],
            [3, 'A quiet drink', SceneKind::Scene, 'firefight', null],
        ], array_map(
            static fn (Scene $scene): array => [$scene->number(), $scene->title(), $scene->kind(), $scene->sceneType(), $scene->hook()],
            $loaded->sessions()[0]->scenes(),
        ));
        self::assertEquals($campaign, $loaded);
    }

    #[Test]
    public function itKeepsTheEndOfEverySessionAndTheFlowPlayed(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $flow = Snapshots::withFlows('heist', 'Heist', 1)->flow('the-heist');
        $campaign = Campaign::create($id, self::OWNER, 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-10T09:00:00+00:00'), [], $flow);
        $campaign->startSession(new \DateTimeImmutable('2026-10-10T09:00:00+00:00'));
        $campaign->endSession(new \DateTimeImmutable('2026-10-10T12:30:00.654321+02:00'));
        $campaign->startSession(new \DateTimeImmutable('2026-10-11T09:00:00+00:00'));

        $this->campaigns()->add($campaign);
        $this->forgetLoaded();
        $loaded = $this->campaigns()->ofId($id);

        self::assertNotNull($loaded);
        self::assertSame('the-heist', $loaded->flowKey());
        self::assertEquals(new \DateTimeImmutable('2026-10-10T12:30:00.654321+02:00'), $loaded->sessions()[0]->endedAt());
        self::assertSame('+02:00', $loaded->sessions()[0]->endedAt()?->format('P'));
        self::assertNull($loaded->sessions()[1]->endedAt());
        self::assertSame(2, $loaded->currentSession()?->number());
        self::assertNotNull($loaded->flowRun());
        self::assertEquals($campaign, $loaded);

        $loaded->endSession(new \DateTimeImmutable('2026-10-11T12:00:00+00:00'));
        $this->campaigns()->save($loaded);
        $this->forgetLoaded();

        self::assertNull($this->campaigns()->ofId($id)?->currentSession());
    }

    /**
     * Flow example 2 (Cyberpunk RED heist), saved and reloaded mid-scene, paused and at a pick with a
     * forced Scene Type: the reloaded campaign equals the saved one, FlowRun included, and goes on.
     */
    #[Test]
    public function itKeepsTheFlowRunInEveryState(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $release = new GameSystemReleaseTranslator()->translate(ReleaseViews::of(ReleaseViews::fixtureContent('examples/cpr-heist')));
        $at = static fn (string $minute): \DateTimeImmutable => new \DateTimeImmutable('2026-10-10T09:'.$minute.':00.250000+02:00');
        $campaign = Campaign::create($id, self::OWNER, 'The job', PinnedRelease::of($release->gameSystemKey(), $release->releaseVersion(), $release->name()), $at('00'), $release->trackers(), $release->flow('heist'));
        $campaign->startSession($at('01'), $release);
        $campaign->pickSceneType('crew', $release, $at('02'));
        $campaign->completeFlowStep('first', StepResult::prompt('Ada, a netrunner'), $release, $at('03'));
        // An endPhase effect (play-flow-run slice 18) ends the phase after this scene.
        $campaign = Campaigns::withFlowRun($campaign, static fn (FlowRun $flowRun) => $flowRun->endPhaseAfterScene());

        // Mid-scene: at a step, with an answer and a phase ending.
        $this->campaigns()->add($campaign);
        $loaded = $this->reloaded($campaign);
        self::assertSame([FlowRunStage::Scene, 'setup', 'second', ['first' => 'Ada, a netrunner'], true], [$loaded->flowRun()?->stage(), $loaded->flowRun()?->part()?->value, $loaded->flowRun()?->stepKey(), $loaded->flowRun()?->answers(), $loaded->flowRun()?->phaseEnding()]);

        // Paused, after a skip, a hand Tracker edit and a hand Scene Type switch.
        $loaded->skipFlowStep('second', $release, $at('04'));
        $loaded->pauseGuidance($at('05'));
        $loaded->setTrackerValue($release->tracker('alarm') ?? throw new \LogicException('No alarm.'), 2, $at('06'));
        $loaded->switchSceneType($release->sceneType('briefing') ?? throw new \LogicException('No briefing.'), $release, $at('07'));
        $this->campaigns()->save($loaded);
        $paused = $this->reloaded($loaded);
        self::assertSame(FlowRunStatus::Paused, $paused->flowRun()?->status());
        self::assertSame(['skip', 'paused', 'trackerEdit', 'sceneTypeSwitch'], array_map(static fn (FlowRunHistoryEntry $entry): string => $entry->event->value, $paused->flowRun()->history()));

        // At the next phase's pick, with a forced Scene Type (a nextScene effect, slice 18).
        $paused->resumeGuidance($release, $at('08'));
        $paused->endFlowScene(1, $release, $at('09'));
        $forcedCampaign = Campaigns::withFlowRun($paused, static fn (FlowRun $flowRun) => $flowRun->forceNextSceneType('getaway'), CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8058'));
        $this->campaigns()->add($forcedCampaign);
        $forced = $this->reloaded($forcedCampaign);
        self::assertSame([1, FlowRunStage::ScenePick, 'getaway', null], [$forced->flowRun()?->phaseIndex(), $forced->flowRun()?->stage(), $forced->flowRun()?->forcedNextSceneType(), $forced->flowRun()?->scene()]);
        self::assertSame(['phase' => 'the-job', 'reason' => 'endPhase'], array_last($forced->flowRun()?->history() ?? [])?->details);

        $forced->pickSceneType('getaway', $release, $at('10'));
        self::assertSame('Getaway 1', $forced->currentScene()?->title());
    }

    /**
     * Flow example 2 (Cyberpunk RED heist) in its first world turn (play-flow-run slice 20): the
     * hook, its hook Scene and a skip in it are kept, and the reloaded campaign goes on.
     */
    #[Test]
    public function itKeepsAFlowRunInAHook(): void
    {
        $release = new GameSystemReleaseTranslator()->translate(ReleaseViews::of(ReleaseViews::fixtureContent('examples/cpr-heist')));
        $at = new \DateTimeImmutable('2026-10-10T09:00:00.250000+02:00');
        $campaign = Campaign::create(CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057'), self::OWNER, 'The job', PinnedRelease::of($release->gameSystemKey(), $release->releaseVersion(), $release->name()), $at, $release->trackers(), $release->flow('heist'));
        $campaign->startSession($at, $release);
        foreach (['crew' => ['first', 'second'], 'briefing' => ['client', 'target', 'payout', 'plan', 'catch'], 'social' => ['talk']] as $sceneType => $steps) {
            $campaign->pickSceneType($sceneType, $release, $at);
            foreach ($steps as $step) {
                'first' === $step ? $campaign->completeFlowStep($step, StepResult::prompt('Ada'), $release, $at) : $campaign->skipFlowStep($step, $release, $at);
            }

            $campaign->endFlowScene((int) $campaign->currentScene()?->number(), $release, $at);
        }

        $campaign->skipFlowStep('gain-edge', $release, $at);
        $this->campaigns()->add($campaign);

        $loaded = $this->reloaded($campaign);
        self::assertSame([FlowRunStage::Hook, Hook::WorldTurn, 'word', [1, 4], 'The world moves'], [$loaded->flowRun()?->stage(), $loaded->flowRun()?->hook(), $loaded->flowRun()?->stepKey(), $loaded->flowRun()?->scene(), $loaded->currentScene()?->title()]);
        $loaded->skipFlowStep('word', $release, $at);
        $this->campaigns()->save($loaded);
        self::assertSame(['step' => 'word', 'part' => 'worldTurn'], array_last($this->reloaded($loaded)->flowRun()?->history() ?? [])?->details);
    }

    /**
     * A Scene Type switch through Effects and an ignored one (play-flow-run slice 19) are kept.
     */
    #[Test]
    public function itKeepsAnIgnoredSceneTypeSwitch(): void
    {
        $release = new GameSystemReleaseTranslator()->translate(ReleaseViews::of(ReleaseViews::fixtureContent('examples/cpr-heist')));
        $at = new \DateTimeImmutable('2026-10-10T09:00:00.250000+02:00');
        $campaign = Campaign::create(CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057'), self::OWNER, 'The job', PinnedRelease::of($release->gameSystemKey(), $release->releaseVersion(), $release->name()), $at, $release->trackers(), $release->flow('heist'));
        $campaign->startSession($at, $release);
        $campaign->pickSceneType('crew', $release, $at);
        $campaign = Campaigns::withHistory($campaign, FlowRunHistoryEntry::sceneTypeSwitch($at, 1, 'crew', 'briefing'), FlowRunHistoryEntry::sceneTypeSwitchIgnored($at, 1, 'crew'));

        $this->campaigns()->add($campaign);

        self::assertSame(['sceneTypeSwitch', 'sceneTypeSwitchIgnored'], array_map(static fn (FlowRunHistoryEntry $entry): string => $entry->event->value, $this->reloaded($campaign)->flowRun()?->history() ?? []));
    }

    /**
     * Pause, save, reload, resume, save, reload: the whole campaign is the same at each step.
     */
    #[Test]
    public function aPausedFlowRunResumesAcrossSaves(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $release = Snapshots::withFlows('heist', 'Heist', 1);
        $at = static fn (string $minute): \DateTimeImmutable => new \DateTimeImmutable('2026-10-10T09:'.$minute.':00.500000+02:00');
        $campaign = Campaign::create($id, self::OWNER, 'The job', PinnedRelease::of('heist', 1, 'Heist'), $at('00'), [], $release->flow('one-shot'));
        $campaign->startSession($at('01'), $release);
        $campaign->pickSceneType('legwork', $release, $at('02'));
        $this->campaigns()->add($campaign);

        $loaded = $this->reloaded($campaign);
        $loaded->pauseGuidance($at('03'));
        $this->campaigns()->save($loaded);
        $paused = $this->reloaded($loaded);
        self::assertSame(FlowRunStatus::Paused, $paused->flowRun()?->status());

        $paused->resumeGuidance($release, $at('04'));
        $this->campaigns()->save($paused);
        $resumed = $this->reloaded($paused);

        self::assertSame([FlowRunStatus::Active, ['paused', 'resumed']], [$resumed->flowRun()?->status(), array_map(static fn (FlowRunHistoryEntry $entry): string => $entry->event->value, $resumed->flowRun()?->history() ?? [])]);
    }

    #[Test]
    public function itKeepsACompletedFlowRunWithItsHistory(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $release = Snapshots::withFlows('heist', 'Heist', 1);
        $campaign = Campaign::create($id, self::OWNER, 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-10T09:00:00+00:00'), [], $release->flow('one-shot'));
        $campaign->startSession(new \DateTimeImmutable('2026-10-10T09:00:00+00:00'), $release);
        $campaign->pickSceneType('legwork', $release, new \DateTimeImmutable('2026-10-10T09:01:00+00:00'));
        $this->campaigns()->add($campaign);
        $loaded = $this->reloaded($campaign);

        $loaded->endFlowScene(1, $release, new \DateTimeImmutable('2026-10-10T09:30:00.123456-05:00'));
        $this->campaigns()->save($loaded);
        $completed = $this->reloaded($loaded);

        self::assertSame(FlowRunStatus::Completed, $completed->flowRun()?->status());
        self::assertSame([FlowRunEvent::PhaseEnded, FlowRunEvent::Completed], array_map(static fn (FlowRunHistoryEntry $entry): FlowRunEvent => $entry->event, $completed->flowRun()->history()));
        self::assertSame('2026-10-10T09:30:00.123456-05:00', $completed->flowRun()->history()[1]->at->format('Y-m-d\\TH:i:s.uP'));
    }

    #[Test]
    public function aCampaignWithPlacesIsKeptWhole(): void
    {
        $places = [
            Place::of(PlaceId::fromString('place-1'), 'Saltmarsh', 'A fishing town', [], ['Town', 'Harbour'], 3),
            Place::of(PlaceId::fromString('place-2'), 'Dunmere', null, [], [], 1, ItemStatus::Closed),
        ];
        $campaign = Campaign::reconstitute(CampaignId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6003'), self::OWNER, 'The marches', PinnedRelease::of('free-journal', 1, 'Free journal'), new \DateTimeImmutable('2026-10-31T09:00:00+00:00'), [], places: $places);
        $this->campaigns()->add($campaign);

        $loaded = $this->reloaded($campaign);
        self::assertEquals($places, $loaded->places());

        $loaded->closePlace(PlaceId::fromString('place-1'));
        $loaded->editPlace(PlaceId::fromString('place-2'), 'Dunmere Keep', 'Ruined', [], ['Ruin'], 2, new GameSystemSnapshot('free-journal', 'Free journal', 1, null, [], []));
        $this->campaigns()->save($loaded);
        $this->reloaded($loaded);
    }

    #[Test]
    public function aTransferredCampaignIsKeptWhole(): void
    {
        $release = new GameSystemSnapshot('free-journal', 'Free journal', 1, null, [], []);
        $npcs = [
            Npc::of(NpcId::fromString('npc-1'), 'Mira', 'Wants the ledger', [], ['Fence'], 3, ItemStatus::Closed, CharacterId::fromString('c-1')),
            Npc::of(NpcId::fromString('npc-2'), 'Brick', null, [], [], 1, ItemStatus::Closed, CharacterId::fromString('c-gone')),
        ];
        $characters = [Character::of(CharacterId::fromString('c-1'), 'Mira', [], [], [], NpcId::fromString('npc-1')), Character::of(CharacterId::fromString('c-gone'), 'Brick', [], [], [], NpcId::fromString('npc-2'))];
        $facts = [Fact::of(FactId::fromString('fact-1'), null, 'Crew', 'Met in a bar', null, [FactLink::npc(NpcId::fromString('npc-1'))])];
        $source = Campaign::reconstitute(CampaignId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6001'), self::OWNER, 'The job', PinnedRelease::of('free-journal', 1, 'Free journal'), new \DateTimeImmutable('2026-10-01T09:00:00+00:00'), [], [], null, null, $npcs, [Thread::of(ThreadId::fromString('thread-1'), 'The vault', [], [], 2)], $facts, $characters);

        $transferred = $source->transfer(CampaignId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6002'), 'Making a Name', $release, null, [CharacterId::fromString('c-1')], new \DateTimeImmutable('2026-10-30T10:00:00+00:00'));
        $this->campaigns()->add($transferred);

        $loaded = $this->reloaded($transferred);
        self::assertNull($loaded->npc(NpcId::fromString('npc-2'))?->promotedTo());
        self::assertSame(['c-1'], array_map(static fn (Character $character): string => $character->id()->toString(), $loaded->characters()));
    }

    /**
     * Reads a stored campaign back as a new request would and checks it equals the one given
     * (FlowRun included).
     */
    private function reloaded(Campaign $campaign): Campaign
    {
        $this->forgetLoaded();
        $loaded = $this->campaigns()->ofId($campaign->id()) ?? throw new \LogicException('The campaign was not stored.');
        self::assertEquals($campaign, $loaded);

        return $loaded;
    }

    #[Test]
    public function anUnknownOrMalformedIdFindsNoCampaign(): void
    {
        $this->campaigns()->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8057', self::OWNER, '2026-10-06'));

        self::assertNull($this->campaigns()->ofId(CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8058')));
        self::assertNull($this->campaigns()->ofId(CampaignId::fromString('not-a-uuid')));
    }

    #[Test]
    public function itRejectsADuplicateId(): void
    {
        $this->campaigns()->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8057', self::OWNER, '2026-10-06'));
        $this->forgetLoaded();

        $this->expectException(CampaignAlreadyExists::class);
        $this->expectExceptionMessageIsOrContains('A campaign with id "01890a5d-ac96-774b-bcce-b302099a8057" already exists.');

        $this->campaigns()->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8057', self::OTHER_OWNER, '2026-10-07'));
    }

    #[Test]
    public function itListsOnlyTheOwnersCampaignsNewestFirst(): void
    {
        $this->campaigns()->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8001', self::OWNER, '2026-10-01'));
        $this->campaigns()->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8003', self::OWNER, '2026-10-03'));
        $this->campaigns()->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8002', self::OTHER_OWNER, '2026-10-02'));
        $this->campaigns()->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8004', self::OWNER, '2026-10-02'));
        $this->forgetLoaded();

        self::assertSame([
            '01890a5d-ac96-774b-bcce-b302099a8003',
            '01890a5d-ac96-774b-bcce-b302099a8004',
            '01890a5d-ac96-774b-bcce-b302099a8001',
        ], $this->idsOf($this->campaigns()->ownedBy(self::OWNER)));
        self::assertSame([], $this->campaigns()->ownedBy(self::UNKNOWN_OWNER));
        self::assertSame([], $this->campaigns()->ownedBy('not-a-uuid'));
    }

    #[Test]
    public function campaignsWithTheSameCreationTimeAreListedByIdDescending(): void
    {
        $this->campaigns()->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8001', self::OWNER, '2026-10-02 10:00:00'));
        $this->campaigns()->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8003', self::OWNER, '2026-10-02 10:00:00'));
        $this->campaigns()->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8002', self::OWNER, '2026-10-02 10:00:00'));
        $this->forgetLoaded();

        self::assertSame([
            '01890a5d-ac96-774b-bcce-b302099a8003',
            '01890a5d-ac96-774b-bcce-b302099a8002',
            '01890a5d-ac96-774b-bcce-b302099a8001',
        ], $this->idsOf($this->campaigns()->ownedBy(self::OWNER)));
    }

    /**
     * What every adapter guarantees about an unsaved change: nothing keeps it when nothing is
     * written. Another write may keep it in some adapters (see CampaignRepository).
     */
    #[Test]
    public function anUnsavedChangeIsNotKeptWhenNothingIsWritten(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $campaign = $this->campaign($id->toString(), self::OWNER, '2026-10-06');
        $this->campaigns()->add($campaign);

        $campaign->startSession(new \DateTimeImmutable('2026-10-06 11:00:00'));
        $this->campaigns()->save($campaign);
        $campaign->startScene('Arrival', new \DateTimeImmutable('2026-10-06 11:05:00'));
        $loaded = $this->campaigns()->ofId($id);
        self::assertNotNull($loaded);
        $loaded->startScene('Arrival', new \DateTimeImmutable('2026-10-06 11:05:00'));
        $this->campaigns()->ownedBy(self::OWNER)[0]->startScene('Arrival', new \DateTimeImmutable('2026-10-06 11:05:00'));
        $this->forgetLoaded();

        self::assertNull($this->campaigns()->ofId($id)?->currentScene());
    }

    #[Test]
    public function savingKeepsTheChangedCampaign(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->campaigns()->add($this->campaign($id->toString(), self::OWNER, '2026-10-06'));
        $this->forgetLoaded();

        $campaign = $this->campaigns()->ofId($id);
        self::assertNotNull($campaign);
        $campaign->startSession(new \DateTimeImmutable('2026-10-06 11:00:00'));
        $campaign->startScene('Arrival', new \DateTimeImmutable('2026-10-06 11:05:00'));
        $this->campaigns()->save($campaign);
        $campaign->endSession(new \DateTimeImmutable('2026-10-06 11:30:00'));
        $campaign->startSession(new \DateTimeImmutable('2026-10-06 12:00:00'));
        $this->forgetLoaded();

        $saved = $this->campaigns()->ofId($id);
        self::assertSame(1, $saved?->currentSession()?->number());
        self::assertSame('Arrival', $saved->currentScene()?->title());

        // A second save on a later load keeps the scene added to the existing session too.
        $saved->startScene('Departure', new \DateTimeImmutable('2026-10-06 11:50:00'));
        $this->campaigns()->save($saved);
        $this->forgetLoaded();

        self::assertSame('Departure', $this->campaigns()->ofId($id)?->currentScene()?->title());
    }

    #[Test]
    public function itKeepsTheTrackerValuesAndTheirChanges(): void
    {
        $snapshot = Snapshots::withTrackers('heist', 'Heist', 1);
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->campaigns()->add(Campaign::create($id, self::OWNER, 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-09T09:00:00+00:00'), $snapshot->trackers()));
        $this->forgetLoaded();

        $loaded = $this->campaigns()->ofId($id);
        self::assertNotNull($loaded);
        self::assertSame(['alarm' => 0, 'chaos' => 5, 'heat' => -5], $this->sorted($loaded->trackerValues()));

        $loaded->setTrackerValue($snapshot->tracker('heat') ?? throw new \LogicException('No heat tracker.'), 3);
        $this->campaigns()->save($loaded);
        $this->forgetLoaded();

        self::assertSame(['alarm' => 0, 'chaos' => 5, 'heat' => 3], $this->sorted($this->campaigns()->ofId($id)?->trackerValues() ?? []));
    }

    #[Test]
    public function aCampaignWithoutTrackersKeepsNone(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->campaigns()->add($this->campaign($id->toString(), self::OWNER, '2026-10-06'));
        $this->forgetLoaded();

        self::assertSame([], $this->campaigns()->ofId($id)?->trackerValues());
    }

    #[Test]
    public function savingACampaignChangedAndSavedElsewhereSinceItWasLoadedFails(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->campaigns()->add($this->campaign($id->toString(), self::OWNER, '2026-10-06'));
        $this->forgetLoaded();
        $mine = $this->campaigns()->ofId($id);
        $theirs = $this->campaignsElsewhere()->ofId($id);
        self::assertNotNull($mine);
        self::assertNotNull($theirs);

        $theirs->startSession(new \DateTimeImmutable('2026-10-06 11:00:00'));
        $theirs->startScene('Theirs', new \DateTimeImmutable('2026-10-06 11:01:00'));
        $this->campaignsElsewhere()->save($theirs);
        $mine->startSession(new \DateTimeImmutable('2026-10-06 11:02:00'));

        try {
            $this->campaigns()->save($mine);
            self::fail('The stale campaign was saved over the newer one.');
        } catch (CampaignModifiedConcurrently $exception) {
            self::assertSame('Campaign "01890a5d-ac96-774b-bcce-b302099a8057" was changed by another request. Reload it and try again.', $exception->getMessage());
        }

        $this->forgetLoaded();
        $kept = $this->campaignsElsewhere()->ofId($id);
        self::assertSame([1], array_map(static fn ($session): int => $session->number(), $kept?->sessions() ?? []));
        self::assertSame('Theirs', $kept?->currentScene()?->title());
    }

    #[Test]
    public function aCampaignCanBeSavedAgainAfterItsOwnSave(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $campaign = $this->campaign($id->toString(), self::OWNER, '2026-10-06');
        $this->campaigns()->add($campaign);

        $campaign->startSession(new \DateTimeImmutable('2026-10-06 11:00:00'));
        $this->campaigns()->save($campaign);
        $campaign->endSession(new \DateTimeImmutable('2026-10-06 11:30:00'));
        $campaign->startSession(new \DateTimeImmutable('2026-10-06 12:00:00'));
        $this->campaigns()->save($campaign);
        $this->forgetLoaded();

        $loaded = $this->campaigns()->ofId($id);
        self::assertSame(2, $loaded?->currentSession()?->number());

        // A later load is not stale either: it sees the newest save.
        $loaded->endSession(new \DateTimeImmutable('2026-10-06 12:30:00'));
        $loaded->startSession(new \DateTimeImmutable('2026-10-06 13:00:00'));
        $this->campaigns()->save($loaded);
        $this->forgetLoaded();

        self::assertSame(3, $this->campaigns()->ofId($id)?->currentSession()?->number());
    }

    private function campaign(string $id, string $ownerId, string $createdAt): Campaign
    {
        return Campaign::create(
            CampaignId::fromString($id),
            $ownerId,
            'Campaign '.$id,
            PinnedRelease::of('free-journal', 1, 'Free journal'),
            new \DateTimeImmutable($createdAt, new \DateTimeZone('UTC')),
        );
    }

    /**
     * Stores need not keep the key order of tracker values (JSONB does not).
     *
     * @param array<string, int> $values
     *
     * @return array<string, int>
     */
    private function sorted(array $values): array
    {
        ksort($values);

        return $values;
    }

    /**
     * @param list<Campaign> $campaigns
     *
     * @return list<string>
     */
    private function idsOf(array $campaigns): array
    {
        return array_map(static fn (Campaign $campaign): string => $campaign->id()->toString(), $campaigns);
    }
}
