<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Persistence;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignAlreadyExists;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunStage;
use App\Play\Domain\Campaign\FlowRun\FlowRunStatus;
use App\Play\Domain\Campaign\FlowRun\PickedItems;
use App\Play\Domain\Campaign\Lists\Npc;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\Thread;
use App\Play\Domain\Campaign\Lists\ThreadId;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\Campaign\SceneKind;
use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\Tag;
use App\Play\Infrastructure\Persistence\Doctrine\CampaignFlowRunType;
use App\Play\Infrastructure\Persistence\Doctrine\CampaignNpcsType;
use App\Play\Infrastructure\Persistence\Doctrine\CampaignSessionsType;
use App\Play\Infrastructure\Persistence\Doctrine\CampaignThreadsType;
use App\Play\Infrastructure\Persistence\Doctrine\DoctrineCampaignRepository;
use App\Tests\Support\Play\CampaignRepositoryContract;
use App\Tests\Support\Play\Snapshots;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(DoctrineCampaignRepository::class)]
#[CoversClass(CampaignSessionsType::class)]
#[CoversClass(CampaignFlowRunType::class)]
#[CoversClass(CampaignNpcsType::class)]
#[CoversClass(CampaignThreadsType::class)]
final class DoctrineCampaignRepositoryTest extends KernelTestCase
{
    use CampaignRepositoryContract;

    private DoctrineCampaignRepository $repository;
    private EntityManagerInterface $entityManager;
    private ?EntityManagerInterface $otherEntityManager = null;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->repository = $container->get(DoctrineCampaignRepository::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    protected function campaigns(): CampaignRepository
    {
        return $this->repository;
    }

    protected function forgetLoaded(): void
    {
        $this->entityManager->clear();
        $this->otherEntityManager?->clear();
    }

    /**
     * A second entity manager on the same connection (so inside the test's transaction): what
     * another request, with its own unit of work, would load and save.
     */
    protected function campaignsElsewhere(): CampaignRepository
    {
        $this->otherEntityManager ??= new EntityManager(
            $this->entityManager->getConnection(),
            $this->entityManager->getConfiguration(),
        );

        return new DoctrineCampaignRepository($this->otherEntityManager);
    }

    #[Test]
    public function itIsThePortAdapter(): void
    {
        self::assertSame($this->repository, self::getContainer()->get(CampaignRepository::class));
    }

    #[Test]
    public function aDuplicateOfACampaignStillLoadedIsRejectedToo(): void
    {
        $this->repository->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8057', self::OWNER, '2026-10-06'));

        $this->expectException(CampaignAlreadyExists::class);

        $this->repository->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8057', self::OTHER_OWNER, '2026-10-07'));
    }

    #[Test]
    public function theFlowRunIsStoredAsJsonbAndACampaignPlayedFreelyHasNone(): void
    {
        $this->repository->add($this->guidedCampaign('01890a5d-ac96-774b-bcce-b302099a8057'));
        $this->repository->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8058', self::OWNER, '2026-10-06'));

        $connection = $this->entityManager->getConnection();
        self::assertSame('jsonb', $connection->fetchOne("SELECT data_type FROM information_schema.columns WHERE table_name = 'play_campaign' AND column_name = 'flow_run'"));
        self::assertEquals([
            'status' => 'paused', 'phaseIndex' => 0, 'stage' => 'scene', 'sessionNumber' => 1, 'sceneNumber' => 1, 'sceneType' => 'legwork',
            'part' => 'open', 'stepKey' => null, 'sequencePosition' => 0, 'scenesPlayed' => 1, 'answers' => [], 'forcedNextSceneType' => null,
            'phaseEnding' => null, 'switchCount' => 0, 'hook' => null, 'phaseOpened' => true, 'sessionOpeningDue' => false, 'picked' => null,
            'history' => [['event' => 'paused', 'at' => '2026-10-10T09:02:00.000000+00:00', 'details' => []]],
        ], $this->storedFlowRun('01890a5d-ac96-774b-bcce-b302099a8057'));
        self::assertNull($connection->fetchOne("SELECT flow_run FROM play_campaign WHERE id = '01890a5d-ac96-774b-bcce-b302099a8058'"));
    }

    #[Test]
    public function aCampaignStoredWithAFlowAndNoFlowRunLoadsGuided(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add($this->guidedCampaign($id->toString()));
        $this->repository->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8058', self::OWNER, '2026-10-06'));
        $this->entityManager->getConnection()->executeStatement('UPDATE play_campaign SET flow_run = NULL WHERE id = ?', [$id->toString()]);
        $this->forgetLoaded();

        $legacy = $this->repository->ofId($id);

        self::assertSame(['one-shot', FlowRunStatus::Active, FlowRunStage::ScenePick], [$legacy?->flowKey(), $legacy?->flowRun()?->status(), $legacy?->flowRun()?->stage()]);
        self::assertNull($this->repository->ofId(CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8058'))?->flowRun());

        $this->forgetLoaded();
        self::assertSame($id->toString(), $this->repository->ownedBy(self::OWNER)[0]->id()->toString());
        self::assertNotNull($this->repository->ofId($id)?->flowRun());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function malformedFlowRuns(): iterable
    {
        $entry = static fn (string $event, array $details): array => ['history' => [['event' => $event, 'at' => '2026-10-10T09:02:00.000000+00:00', 'details' => $details]]];

        yield 'unknown status' => [['status' => 'lost'], 'Stored campaign FlowRun: "status" must be one of "active", "paused", "completed".'];
        yield 'no phase index' => [['phaseIndex' => null], 'Stored campaign FlowRun: "phaseIndex" must be an integer.'];
        yield 'unknown stage' => [['stage' => 'interlude'], 'Stored campaign FlowRun: "stage" must be one of "scenePick", "scene", "hook".'];
        yield 'stage hook without a hook' => [['stage' => 'hook'], 'Stored campaign FlowRun: "hook" must be set at stage "hook".'];
        yield 'a hook outside stage hook' => [['hook' => 'worldTurn'], 'Stored campaign FlowRun: "hook" must be null outside stage "hook".'];
        yield 'unknown hook' => [['stage' => 'hook', 'hook' => 'dawn'], 'Stored campaign FlowRun: "hook" must be one of "sessionOpening", "sessionClosing", "phaseOpening", "phaseClosing", "worldTurn".'];
        yield 'phase opened not a boolean' => [['phaseOpened' => 1], 'Stored campaign FlowRun: "phaseOpened" must be a boolean.'];
        yield 'session opening due null' => [['sessionOpeningDue' => null], 'Stored campaign FlowRun: "sessionOpeningDue" must be a boolean.'];
        yield 'unknown part' => [['part' => 'epilogue'], 'Stored campaign FlowRun: "part" must be one of "sceneOpening", "setup", "play", "open", "closing", "sceneClosing".'];
        yield 'scene number not an integer' => [['sceneNumber' => '1'], 'Stored campaign FlowRun: "sceneNumber" must be an integer.'];
        yield 'answer not a string' => [['answers' => ['plan' => 3]], 'Stored campaign FlowRun: "answers" must be an object of strings.'];
        yield 'unknown phase ending' => [['phaseEnding' => 'later'], 'Stored campaign FlowRun: "phaseEnding" must be one of "endPhase", "moveOn", "finished".'];
        yield 'picked not an object' => [['picked' => 'Ana'], 'Stored campaign FlowRun: "kind" must be one of "npc", "thread".'];
        yield 'picked of an unknown kind' => [['picked' => ['kind' => 'faction', 'ids' => ['a']]], 'Stored campaign FlowRun: "kind" must be one of "npc", "thread".'];
        yield 'picked ids not a list' => [['picked' => ['kind' => 'npc', 'ids' => 'a']], 'Stored campaign FlowRun: "picked" must be a list.'];
        yield 'a picked id not a string' => [['picked' => ['kind' => 'npc', 'ids' => [1]]], 'Stored campaign FlowRun: "picked" must be a list of id strings.'];
        yield 'picked without ids' => [['picked' => ['kind' => 'npc', 'ids' => []]], 'Stored campaign FlowRun: "picked" is invalid: Picked items hold 1 to 10 ids, got 0.'];
        yield 'picked with a repeated id' => [['picked' => ['kind' => 'npc', 'ids' => ['a', 'a']]], 'Stored campaign FlowRun: "picked" is invalid: Picked item ids must be distinct and not blank.'];
        yield 'picked with more than ten ids' => [['picked' => ['kind' => 'npc', 'ids' => array_map(strval(...), range(1, 11))]], 'Stored campaign FlowRun: "picked" is invalid: Picked items hold 1 to 10 ids, got 11.'];
        yield 'picked outside a Scene' => [['picked' => ['kind' => 'npc', 'ids' => ['a']], 'sceneNumber' => null], 'Stored campaign FlowRun: "picked" must be null outside a Scene.'];
        yield 'history not a list' => [['history' => 'none'], 'Stored campaign FlowRun: "history" must be a list.'];
        yield 'unknown event' => [$entry('teleported', []), 'Stored campaign FlowRun: "event" must be one of "skip", "trackerEdit", "sceneTypeSwitch", "sceneTypeSwitchIgnored", "paused", "resumed", "sceneAbandoned", "phaseEnded", "completed".'];
        yield 'malformed time' => [['history' => [['event' => 'paused', 'at' => 'yesterday', 'details' => []]]], 'Stored campaign FlowRun: "at" must be a time like 2026-10-06T10:00:00.000000+00:00.'];
        yield 'skip of an unknown part' => [$entry('skip', ['step' => 'plan', 'part' => 'later']), 'Stored campaign FlowRun: "part" must be one of "sceneOpening", "setup", "play", "open", "closing", "sceneClosing", "sessionOpening", "sessionClosing", "phaseOpening", "phaseClosing", "worldTurn".'];
        yield 'tracker edit from a string' => [$entry('trackerEdit', ['tracker' => 'heat', 'from' => '1', 'to' => 2]), 'Stored campaign FlowRun: "from" must be an integer.'];
        yield 'switch without its target' => [$entry('sceneTypeSwitch', ['scene' => 1, 'from' => null]), 'Stored campaign FlowRun: "to" must be a string.'];
        yield 'ignored switch without its Scene Type' => [$entry('sceneTypeSwitchIgnored', ['scene' => 1]), 'Stored campaign FlowRun: "sceneType" must be a string.'];
        yield 'abandoned scene without its session' => [$entry('sceneAbandoned', ['scene' => 1]), 'Stored campaign FlowRun: "session" must be an integer.'];
        yield 'phase ended for an unknown reason' => [$entry('phaseEnded', ['phase' => 'plan', 'reason' => 'boredom']), 'Stored campaign FlowRun: "reason" must be one of "finished", "moveOn", "endPhase".'];
    }

    /**
     * @param array<string, mixed> $fields replace the stored FlowRun's fields
     */
    #[Test]
    #[DataProvider('malformedFlowRuns')]
    public function aMalformedStoredFlowRunFailsToLoad(array $fields, string $error): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add($this->guidedCampaign($id->toString()));
        $this->entityManager->getConnection()->executeStatement('UPDATE play_campaign SET flow_run = ? WHERE id = ?', [json_encode($fields + $this->storedFlowRun($id->toString()), \JSON_THROW_ON_ERROR), $id->toString()]);
        $this->forgetLoaded();

        $this->expectExceptionMessageIsOrContains($error);

        $this->repository->ofId($id);
    }

    /**
     * A FlowRun stored before hooks ran (play-flow-run slice 20) has no hook, phase opening or
     * session opening fields: the current phase's opening has run and no session opening is due.
     */
    #[Test]
    public function aFlowRunStoredBeforeHooksReadsAsPastThePhaseOpening(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add($this->guidedCampaign($id->toString()));
        $legacy = array_diff_key($this->storedFlowRun($id->toString()), array_flip(['hook', 'phaseOpened', 'sessionOpeningDue', 'picked']));
        $legacy['history'] = [['event' => 'skip', 'at' => '2026-10-10T09:02:00.000000+00:00', 'details' => ['step' => 'plan', 'part' => 'setup']]];
        $this->entityManager->getConnection()->executeStatement('UPDATE play_campaign SET flow_run = ? WHERE id = ?', [json_encode($legacy, \JSON_THROW_ON_ERROR), $id->toString()]);
        $this->forgetLoaded();

        $flowRun = $this->repository->ofId($id)?->flowRun();

        self::assertSame([FlowRunStage::Scene, null, true, false, null, 'setup'], [$flowRun?->stage(), $flowRun?->hook(), $flowRun?->phaseOpened(), $flowRun?->sessionOpeningDue(), $flowRun?->picked(), array_last($flowRun?->history() ?? [])?->details['part']]);
    }

    #[Test]
    public function thePickedItemsRoundTripWithTheWholeFlowRun(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $base = $this->guidedCampaign($id->toString());
        $run = $base->flowRun() ?? throw new \LogicException('No FlowRun.');
        $flowRun = FlowRun::reconstitute($run->status(), $run->phaseIndex(), $run->stage(), $run->scene()[0] ?? null, $run->scene()[1] ?? null, $run->sceneType(), $run->part(), $run->stepKey(), $run->sequencePosition(), $run->scenesPlayed(), $run->answers(), $run->forcedNextSceneType(), $run->phaseEndingReason(), $run->switchCount(), $run->history(), $run->hook(), $run->phaseOpened(), $run->sessionOpeningDue(), new PickedItems(ItemKind::Thread, ['t2', 't1']));
        $campaign = Campaign::reconstitute($id, $base->ownerId(), $base->name(), $base->pinnedRelease(), $base->createdAt(), $base->sessions(), [], $base->flowKey(), $flowRun);
        $this->repository->add($campaign);
        $this->forgetLoaded();

        $loaded = $this->repository->ofId($id);

        self::assertEquals($campaign, $loaded);
        self::assertEquals(new PickedItems(ItemKind::Thread, ['t2', 't1']), $loaded?->flowRun()?->picked());
        self::assertEquals(['kind' => 'thread', 'ids' => ['t2', 't1']], $this->storedFlowRun($id->toString())['picked']);
    }

    /**
     * @return array<mixed> the stored FlowRun column, decoded
     */
    private function storedFlowRun(string $id): array
    {
        $stored = $this->entityManager->getConnection()->fetchOne('SELECT flow_run FROM play_campaign WHERE id = ?', [$id]);
        self::assertIsString($stored);
        $decoded = json_decode($stored, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * A campaign along Flow "one-shot" in open play of its first scene, guidance paused.
     */
    private function guidedCampaign(string $id): Campaign
    {
        $release = Snapshots::withFlows('heist', 'Heist', 1);
        $campaign = Campaign::create(CampaignId::fromString($id), self::OWNER, 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-10T09:00:00+00:00'), [], $release->flow('one-shot'));
        $campaign->startSession(new \DateTimeImmutable('2026-10-10T09:00:00+00:00'), $release);
        $campaign->pickSceneType('legwork', $release, new \DateTimeImmutable('2026-10-10T09:01:00+00:00'));
        $campaign->pauseGuidance(new \DateTimeImmutable('2026-10-10T09:02:00+00:00'));

        return $campaign;
    }

    #[Test]
    public function sessionsAreStoredAsJsonbAndTimestampsWithTheirTimeZone(): void
    {
        $campaign = $this->campaign('01890a5d-ac96-774b-bcce-b302099a8057', self::OWNER, '2026-10-06');
        $campaign->startSession(new \DateTimeImmutable('2026-10-06T10:05:00+02:00'));
        $campaign->startScene('At the gate', new \DateTimeImmutable('2026-10-06T10:06:00+02:00'));
        $this->repository->add($campaign);

        $connection = $this->entityManager->getConnection();
        $types = $connection->fetchAllKeyValue(
            "SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'play_campaign' AND column_name IN ('sessions', 'created_at') ORDER BY column_name",
        );
        self::assertSame(['created_at' => 'timestamp with time zone', 'sessions' => 'jsonb'], $types);

        $sessions = $connection->fetchOne(
            "SELECT sessions FROM play_campaign WHERE id = '01890a5d-ac96-774b-bcce-b302099a8057'",
        );
        self::assertIsString($sessions);
        self::assertEquals(
            [['number' => 1, 'startedAt' => '2026-10-06T10:05:00.000000+02:00', 'scenes' => [['number' => 1, 'title' => 'At the gate', 'startedAt' => '2026-10-06T10:06:00.000000+02:00', 'kind' => 'scene', 'sceneType' => null, 'hook' => null]], 'endedAt' => null]],
            json_decode($sessions, true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function aSceneStoredBeforeScenesHadAKindReadsAsASceneOfPlayWithoutASceneType(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add($this->campaign($id->toString(), self::OWNER, '2026-10-06'));
        $this->storeSessions($id, '[{"number": 1, "startedAt": "2026-10-06T10:05:00.000000+02:00", "scenes": [{"number": 1, "title": "At the gate", "startedAt": "2026-10-06T10:06:00.000000+02:00"}]}]');

        $scene = $this->repository->ofId($id)?->currentScene();

        self::assertNotNull($scene);
        self::assertSame(['At the gate', SceneKind::Scene, null, null], [$scene->title(), $scene->kind(), $scene->sceneType(), $scene->hook()]);
    }

    #[Test]
    public function aSessionStoredBeforeSessionsEndedIsUnderWayAndACampaignStoredBeforeFlowsPlaysFreely(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add($this->campaign($id->toString(), self::OWNER, '2026-10-06'));
        $this->storeSessions($id, '[{"number": 1, "startedAt": "2026-10-06T10:05:00.000000+02:00", "scenes": []}]');

        $campaign = $this->repository->ofId($id);

        self::assertNotNull($campaign);
        self::assertSame(1, $campaign->currentSession()?->number());
        self::assertNull($campaign->sessions()[0]->endedAt());
        self::assertNull($campaign->flowKey());
        self::assertNull($this->entityManager->getConnection()->fetchOne('SELECT flow_key FROM play_campaign WHERE id = ?', [$id->toString()]));
    }

    #[Test]
    public function aMalformedSessionEndFailsToLoad(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add($this->campaign($id->toString(), self::OWNER, '2026-10-06'));
        $this->storeSessions($id, '[{"number": 1, "startedAt": "2026-10-06T10:05:00.000000+02:00", "scenes": [], "endedAt": "yesterday"}]');

        $this->expectExceptionMessageIsOrContains('Stored campaign sessions: "endedAt" must be a time like 2026-10-06T10:00:00.000000+00:00.');

        $this->repository->ofId($id);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedScenes(): iterable
    {
        yield 'unknown kind' => ['"kind": "interlude"', 'Stored campaign sessions: "kind" must be one of "scene", "hook".'];
        yield 'non-string Scene Type' => ['"sceneType": 7', 'Stored campaign sessions: "sceneType" must be a string.'];
        yield 'hook kind without a hook' => ['"kind": "hook", "hook": null', 'Stored campaign sessions: "hook" must be set on a hook scene.'];
        yield 'hook name on a scene of play' => ['"kind": "scene", "hook": "worldTurn"', 'Stored campaign sessions: "hook" must be null on a scene of play.'];
        yield 'hook name without a kind' => ['"hook": "worldTurn"', 'Stored campaign sessions: "hook" must be null on a scene of play.'];
        yield 'unknown hook' => ['"kind": "hook", "hook": "dawn"', 'Stored campaign sessions: "hook" must be one of "sessionOpening", "sessionClosing", "phaseOpening", "phaseClosing", "worldTurn".'];
    }

    #[Test]
    #[DataProvider('malformedScenes')]
    public function aMalformedStoredSceneFailsToLoad(string $fields, string $error): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add($this->campaign($id->toString(), self::OWNER, '2026-10-06'));
        $this->storeSessions($id, \sprintf('[{"number": 1, "startedAt": "2026-10-06T10:05:00.000000+02:00", "scenes": [{"number": 1, "title": "At the gate", "startedAt": "2026-10-06T10:06:00.000000+02:00", %s}]}]', $fields));

        $this->expectExceptionMessageIsOrContains($error);

        $this->repository->ofId($id);
    }

    #[Test]
    public function npcsAndThreadsRoundTripWithTheWholeCampaign(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $campaign = $this->campaignWithLists($id);
        $this->repository->add($campaign);
        $this->forgetLoaded();

        $loaded = $this->repository->ofId($id);

        self::assertEquals($campaign, $loaded);
        self::assertSame(['Mira', 'Brann', 'Old Tam'], array_map(static fn ($npc): string => $npc->name(), $loaded?->npcs() ?? []));
        $connection = $this->entityManager->getConnection();
        self::assertSame(['npcs' => 'jsonb', 'threads' => 'jsonb'], $connection->fetchAllKeyValue(
            "SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'play_campaign' AND column_name IN ('npcs', 'threads') ORDER BY column_name",
        ));
        self::assertEquals(
            [['id' => 'npc-1', 'name' => 'Mira', 'agenda' => 'Wants the ledger', 'tags' => ['ally', 'rival'], 'freeTags' => ['Fence'], 'weight' => 3, 'status' => 'open']],
            \array_slice($this->storedList($id->toString(), 'npcs'), 0, 1),
        );
    }

    #[Test]
    public function aChangeToAListedItemIsSaved(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add($this->campaignWithLists($id));
        $this->forgetLoaded();
        $campaign = $this->repository->ofId($id);
        self::assertNotNull($campaign);

        $campaign->closeNpc(NpcId::fromString('npc-1'));
        $campaign->editThread(ThreadId::fromString('thread-1'), 'The vault job', ['ally'], [], 2, $this->release());
        $this->repository->save($campaign);
        $this->forgetLoaded();

        $loaded = $this->repository->ofId($id);
        self::assertEquals($campaign, $loaded);
        self::assertSame(['Brann', 'Mira', 'Old Tam'], array_map(static fn ($npc): string => $npc->name(), $loaded?->npcs() ?? []));
        self::assertSame('The vault job', $loaded?->thread(ThreadId::fromString('thread-1'))?->name());
    }

    #[Test]
    public function aRowStoredBeforeListsExistedReadsWithNoNpcsOrThreads(): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement(
            "INSERT INTO play_campaign (id, owner_id, name, game_system_key, release_version, game_system_name, created_at, sessions, tracker_values) VALUES ('01890a5d-ac96-774b-bcce-b302099a8057', ?, 'Old', 'heist', 1, 'Heist', '2026-10-06 10:00:00+00', '[]', '{}')",
            [self::OWNER],
        );

        $campaign = $this->repository->ofId(CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057'));

        self::assertSame([[], []], [$campaign?->npcs(), $campaign?->threads()]);
        self::assertSame(['[]', '[]'], [$connection->fetchOne('SELECT npcs FROM play_campaign'), $connection->fetchOne('SELECT threads FROM play_campaign')]);
    }

    #[Test]
    public function aSaveAfterAListChangeRefusesAStaleCopy(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add(Campaign::create($id, self::OWNER, 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-10T09:00:00+00:00')));
        $this->forgetLoaded();
        $mine = $this->repository->ofId($id);
        $theirs = $this->campaignsElsewhere()->ofId($id);
        self::assertNotNull($mine);
        self::assertNotNull($theirs);

        $theirs->addNpc(NpcId::fromString('npc-1'), 'Mira', null, [], [], 1, $this->release());
        $this->campaignsElsewhere()->save($theirs);
        $mine->addThread(ThreadId::fromString('thread-1'), 'The vault job', [], [], 1, $this->release());

        $this->expectException(CampaignModifiedConcurrently::class);

        $this->repository->save($mine);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function malformedLists(): iterable
    {
        $npcRow = static fn (array $fields): array => $fields + ['id' => 'npc-1', 'name' => 'Mira', 'agenda' => null, 'tags' => [], 'freeTags' => [], 'weight' => 1, 'status' => 'open'];
        $threadRow = static fn (array $fields): array => $fields + ['id' => 'thread-1', 'name' => 'Vault', 'tags' => [], 'freeTags' => [], 'weight' => 1, 'status' => 'open'];
        $npc = static fn (array $fields): string => json_encode([$npcRow($fields)], \JSON_THROW_ON_ERROR);
        $thread = static fn (array $fields): string => json_encode([$threadRow($fields)], \JSON_THROW_ON_ERROR);

        yield 'npcs not a list' => ['npcs', '{"a": 1}', 'Stored campaign npcs: "npcs" must be a list.'];
        yield 'npc unknown status' => ['npcs', $npc(['status' => 'lost']), 'Stored campaign npcs: "status" must be one of "open", "closed".'];
        yield 'npc missing name' => ['npcs', $npc(['name' => null]), 'Stored campaign npcs: "name" must be a string.'];
        yield 'npc weight not an integer' => ['npcs', $npc(['weight' => '2']), 'Stored campaign npcs: "weight" must be an integer.'];
        yield 'npc tags not strings' => ['npcs', $npc(['tags' => [3]]), 'Stored campaign npcs: "tags" must be a list of strings.'];
        yield 'npc agenda not a string' => ['npcs', $npc(['agenda' => 4]), 'Stored campaign npcs: "agenda" must be a string.'];
        yield 'npc weight out of range' => ['npcs', $npc(['weight' => 4]), 'The weight must be 1 to 3, got 4.'];
        yield 'npc duplicate tag' => ['npcs', $npc(['tags' => ['ally', 'ally']]), 'Tag "ally" is listed twice.'];
        yield 'npc blank name' => ['npcs', $npc(['name' => ' ']), 'The name must not be blank.'];
        yield 'npc blank id' => ['npcs', $npc(['id' => '']), 'An NPC id must not be blank.'];
        yield 'npc free tags not strings' => ['npcs', $npc(['freeTags' => 'Fence']), 'Stored campaign npcs: "freeTags" must be a list of strings.'];
        yield 'npc duplicate id' => ['npcs', json_encode([$npcRow([]), $npcRow(['name' => 'Brann'])], \JSON_THROW_ON_ERROR), 'Stored campaign npcs: id "npc-1" is listed twice.'];
        yield 'npcs over the limit' => ['npcs', json_encode(array_map(static fn (int $i): array => ['id' => 'npc-'.$i, 'name' => 'NPC '.$i, 'agenda' => null, 'tags' => [], 'freeTags' => [], 'weight' => 1, 'status' => 'open'], range(1, 501)), \JSON_THROW_ON_ERROR), 'Stored campaign npcs: at most 500 NPCs are allowed, got 501.'];
        yield 'threads not a list' => ['threads', '{"a": 1}', 'Stored campaign threads: "threads" must be a list.'];
        yield 'thread unknown status' => ['threads', $thread(['status' => 'done']), 'Stored campaign threads: "status" must be one of "open", "closed".'];
        yield 'thread free tag duplicate' => ['threads', $thread(['freeTags' => ['Fence', 'fence']]), 'Free tag "fence" is listed twice.'];
        yield 'thread missing name' => ['threads', $thread(['name' => null]), 'Stored campaign threads: "name" must be a string.'];
        yield 'thread weight not an integer' => ['threads', $thread(['weight' => '2']), 'Stored campaign threads: "weight" must be an integer.'];
        yield 'thread tags not strings' => ['threads', $thread(['tags' => [3]]), 'Stored campaign threads: "tags" must be a list of strings.'];
        yield 'thread free tags not strings' => ['threads', $thread(['freeTags' => 'Heist']), 'Stored campaign threads: "freeTags" must be a list of strings.'];
        yield 'thread weight out of range' => ['threads', $thread(['weight' => 0]), 'The weight must be 1 to 3, got 0.'];
        yield 'thread duplicate tag' => ['threads', $thread(['tags' => ['ally', 'ally']]), 'Tag "ally" is listed twice.'];
        yield 'thread blank name' => ['threads', $thread(['name' => ' ']), 'The name must not be blank.'];
        yield 'thread blank id' => ['threads', $thread(['id' => '']), 'A Thread id must not be blank.'];
        yield 'thread duplicate id' => ['threads', json_encode([$threadRow([]), $threadRow(['name' => 'Debts'])], \JSON_THROW_ON_ERROR), 'Stored campaign threads: id "thread-1" is listed twice.'];
        yield 'threads over the limit' => ['threads', json_encode(array_map(static fn (int $i): array => ['id' => 'thread-'.$i, 'name' => 'Thread '.$i, 'tags' => [], 'freeTags' => [], 'weight' => 1, 'status' => 'open'], range(1, 501)), \JSON_THROW_ON_ERROR), 'Stored campaign threads: at most 500 Threads are allowed, got 501.'];
        yield 'thread missing id' => ['threads', $thread(['id' => null]), 'Stored campaign threads: "id" must be a string.'];
    }

    #[Test]
    #[DataProvider('malformedLists')]
    public function aMalformedStoredListFailsToLoad(string $column, string $stored, string $error): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add($this->campaign($id->toString(), self::OWNER, '2026-10-06'));
        $this->entityManager->getConnection()->executeStatement(\sprintf('UPDATE play_campaign SET %s = ? WHERE id = ?', $column), [$stored, $id->toString()]);
        $this->forgetLoaded();

        $this->expectExceptionMessageIsOrContains($error);

        $this->repository->ofId($id);
    }

    /**
     * @return iterable<string, array{CampaignNpcsType|CampaignThreadsType, mixed}>
     */
    public static function valuesThatAreNotLists(): iterable
    {
        yield 'npcs: a string' => [new CampaignNpcsType(), 'npc-1'];
        yield 'npcs: a stored-shape array item' => [new CampaignNpcsType(), [['id' => 'npc-1']]];
        yield 'npcs: a thread among them' => [new CampaignNpcsType(), [Thread::of(ThreadId::fromString('thread-1'), 'Vault', [], [], 1)]];
        yield 'threads: a string' => [new CampaignThreadsType(), 'thread-1'];
        yield 'threads: a stored-shape array item' => [new CampaignThreadsType(), [['id' => 'thread-1']]];
        yield 'threads: an npc among them' => [new CampaignThreadsType(), [Npc::of(NpcId::fromString('npc-1'), 'Mira', null, [], [], 1)]];
    }

    #[Test]
    #[DataProvider('valuesThatAreNotLists')]
    public function aValueThatIsNotAListOfItemsIsRefusedBeforeItIsWritten(CampaignNpcsType|CampaignThreadsType $type, mixed $value): void
    {
        $this->expectException(InvalidType::class);

        $type->convertToDatabaseValue($value, $this->entityManager->getConnection()->getDatabasePlatform());
    }

    private function release(): GameSystemSnapshot
    {
        return new GameSystemSnapshot('heist', 'Heist', 1, null, [], [], tags: [new Tag('ally', 'Ally'), new Tag('rival', 'Rival', 'Someone in the way')]);
    }

    /**
     * A campaign with three NPCs (the second closed) and two Threads (the last closed): agendas null and set, tags, free tags and weights vary.
     */
    private function campaignWithLists(CampaignId $id): Campaign
    {
        $release = $this->release();
        $campaign = Campaign::create($id, self::OWNER, 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-10T09:00:00+00:00'));
        $campaign->addNpc(NpcId::fromString('npc-1'), 'Mira', 'Wants the ledger', ['ally', 'rival'], ['Fence'], 3, $release);
        $campaign->addNpc(NpcId::fromString('npc-2'), 'Old Tam', null, [], [], 1, $release);
        $campaign->addNpc(NpcId::fromString('npc-3'), 'Brann', 'Keeps the keys', ['rival'], ['Smith', 'Night shift'], 2, $release);
        $campaign->closeNpc(NpcId::fromString('npc-2'));
        $campaign->addThread(ThreadId::fromString('thread-1'), 'Rob the vault', ['ally'], ['Heist'], 2, $release);
        $campaign->addThread(ThreadId::fromString('thread-2'), 'Old debts', [], [], 1, $release);
        $campaign->closeThread(ThreadId::fromString('thread-2'));

        return $campaign;
    }

    /**
     * @return list<mixed> a stored list column, decoded, in stored (creation) order
     */
    private function storedList(string $id, string $column): array
    {
        $stored = $this->entityManager->getConnection()->fetchOne(\sprintf('SELECT %s FROM play_campaign WHERE id = ?', $column), [$id]);
        self::assertIsString($stored);
        $decoded = json_decode($stored, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return array_values($decoded);
    }

    private function storeSessions(CampaignId $id, string $sessions): void
    {
        $this->entityManager->getConnection()->executeStatement('UPDATE play_campaign SET sessions = ? WHERE id = ?', [$sessions, $id->toString()]);
        $this->forgetLoaded();
    }

    #[Test]
    public function everySaveBumpsTheVersionColumn(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $campaign = $this->campaign($id->toString(), self::OWNER, '2026-10-06');
        $this->repository->add($campaign);
        self::assertSame(1, $campaign->version());

        $campaign->startSession(new \DateTimeImmutable('2026-10-06 11:00:00'));
        $this->repository->save($campaign);
        self::assertSame(2, $campaign->version());

        $this->forgetLoaded();
        self::assertSame(2, $this->repository->ofId($id)?->version());
        self::assertSame(2, $this->entityManager->getConnection()->fetchOne('SELECT version FROM play_campaign WHERE id = ?', [$id->toString()]));
    }

    #[Test]
    public function savingACampaignThatWasNeverAddedFails(): void
    {
        $this->expectException(\LogicException::class);

        $this->repository->save($this->campaign('01890a5d-ac96-774b-bcce-b302099a8057', self::OWNER, '2026-10-06'));
    }

    #[Test]
    public function aCampaignAddedConcurrentlyWithTheSameIdIsRejectedByThePrimaryKey(): void
    {
        // Another request inserts the same id after this one looked it up, right before it flushes.
        $connection = $this->entityManager->getConnection();
        $concurrentInsert = new readonly class($connection) {
            public function __construct(private \Doctrine\DBAL\Connection $connection)
            {
            }

            public function preFlush(): void
            {
                $this->connection->executeStatement(
                    "INSERT INTO play_campaign (id, owner_id, name, game_system_key, release_version, game_system_name, created_at, sessions, tracker_values) VALUES ('01890a5d-ac96-774b-bcce-b302099a8057', '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f5002', 'Theirs', 'free-journal', 1, 'Free journal', '2026-10-06 10:00:00+00', '[]', '{}')",
                );
            }
        };
        $this->entityManager->getEventManager()->addEventListener([Events::preFlush], $concurrentInsert);

        try {
            $this->repository->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8057', self::OWNER, '2026-10-06'));
            self::fail('The concurrent duplicate was not rejected.');
        } catch (CampaignAlreadyExists $exception) {
            self::assertSame('A campaign with id "01890a5d-ac96-774b-bcce-b302099a8057" already exists.', $exception->getMessage());
        } finally {
            $this->entityManager->getEventManager()->removeEventListener([Events::preFlush], $concurrentInsert);
        }
    }

    /**
     * Documents why the port asks callers to save every change (CampaignRepository::save): Doctrine
     * flushes its whole unit of work, so another write keeps an unsaved change of a loaded campaign.
     */
    #[Test]
    public function anotherWriteAlsoKeepsAnUnsavedChangeOfALoadedCampaign(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $this->repository->add($this->campaign($id->toString(), self::OWNER, '2026-10-06'));
        $this->repository->ofId($id)?->startSession(new \DateTimeImmutable('2026-10-06 11:00:00'));

        $this->repository->add($this->campaign('01890a5d-ac96-774b-bcce-b302099a8058', self::OWNER, '2026-10-07'));
        $this->forgetLoaded();

        self::assertSame(1, $this->repository->ofId($id)?->currentSession()?->number());
    }

    #[Test]
    public function theCreationTimeKeepsItsMicroseconds(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');
        $campaign = $this->campaign($id->toString(), self::OWNER, '2026-10-06 10:00:00.123456');
        $this->repository->add($campaign);
        $this->forgetLoaded();

        $loaded = $this->repository->ofId($id);

        self::assertNotNull($loaded);
        self::assertSame('2026-10-06T10:00:00.123456+00:00', $loaded->createdAt()->format('Y-m-d\\TH:i:s.uP'));
        self::assertEquals($campaign, $loaded);
        // The schema tool cannot see a column's precision (doctrine.yaml mapping_types): check it here.
        self::assertSame(6, $this->entityManager->getConnection()->fetchOne(
            "SELECT datetime_precision FROM information_schema.columns WHERE table_name = 'play_campaign' AND column_name = 'created_at'",
        ));
    }
}
