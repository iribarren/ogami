<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Persistence;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignAlreadyExists;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\Campaign\SceneKind;
use App\Play\Infrastructure\Persistence\Doctrine\CampaignFlowRunType;
use App\Play\Infrastructure\Persistence\Doctrine\CampaignSessionsType;
use App\Play\Infrastructure\Persistence\Doctrine\DoctrineCampaignRepository;
use App\Tests\Support\Play\CampaignRepositoryContract;
use App\Tests\Support\Play\Snapshots;
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
            'phaseEnding' => null, 'switchCount' => 0, 'history' => [['event' => 'paused', 'at' => '2026-10-10T09:02:00.000000+00:00', 'details' => []]],
        ], $this->storedFlowRun('01890a5d-ac96-774b-bcce-b302099a8057'));
        self::assertNull($connection->fetchOne("SELECT flow_run FROM play_campaign WHERE id = '01890a5d-ac96-774b-bcce-b302099a8058'"));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function malformedFlowRuns(): iterable
    {
        $entry = static fn (string $event, array $details): array => ['history' => [['event' => $event, 'at' => '2026-10-10T09:02:00.000000+00:00', 'details' => $details]]];

        yield 'unknown status' => [['status' => 'lost'], 'Stored campaign FlowRun: "status" must be one of "active", "paused", "completed".'];
        yield 'no phase index' => [['phaseIndex' => null], 'Stored campaign FlowRun: "phaseIndex" must be an integer.'];
        yield 'unknown stage' => [['stage' => 'hook'], 'Stored campaign FlowRun: "stage" must be one of "scenePick", "scene".'];
        yield 'unknown part' => [['part' => 'epilogue'], 'Stored campaign FlowRun: "part" must be one of "sceneOpening", "setup", "play", "open", "closing", "sceneClosing".'];
        yield 'scene number not an integer' => [['sceneNumber' => '1'], 'Stored campaign FlowRun: "sceneNumber" must be an integer.'];
        yield 'answer not a string' => [['answers' => ['plan' => 3]], 'Stored campaign FlowRun: "answers" must be an object of strings.'];
        yield 'unknown phase ending' => [['phaseEnding' => 'later'], 'Stored campaign FlowRun: "phaseEnding" must be one of "endPhase", "moveOn".'];
        yield 'history not a list' => [['history' => 'none'], 'Stored campaign FlowRun: "history" must be a list.'];
        yield 'unknown event' => [$entry('teleported', []), 'Stored campaign FlowRun: "event" must be one of "skip", "trackerEdit", "sceneTypeSwitch", "paused", "resumed", "sceneAbandoned", "phaseEnded", "completed".'];
        yield 'malformed time' => [['history' => [['event' => 'paused', 'at' => 'yesterday', 'details' => []]]], 'Stored campaign FlowRun: "at" must be a time like 2026-10-06T10:00:00.000000+00:00.'];
        yield 'skip of an unknown part' => [$entry('skip', ['step' => 'plan', 'part' => 'later']), 'Stored campaign FlowRun: "part" must be one of'];
        yield 'tracker edit from a string' => [$entry('trackerEdit', ['tracker' => 'heat', 'from' => '1', 'to' => 2]), 'Stored campaign FlowRun: "from" must be an integer.'];
        yield 'switch without its target' => [$entry('sceneTypeSwitch', ['scene' => 1, 'from' => null]), 'Stored campaign FlowRun: "to" must be a string.'];
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
