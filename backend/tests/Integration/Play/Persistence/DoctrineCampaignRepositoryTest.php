<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Persistence;

use App\Play\Domain\Campaign\CampaignAlreadyExists;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\SceneKind;
use App\Play\Infrastructure\Persistence\Doctrine\CampaignSessionsType;
use App\Play\Infrastructure\Persistence\Doctrine\DoctrineCampaignRepository;
use App\Tests\Support\Play\CampaignRepositoryContract;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(DoctrineCampaignRepository::class)]
#[CoversClass(CampaignSessionsType::class)]
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
            [['number' => 1, 'startedAt' => '2026-10-06T10:05:00.000000+02:00', 'scenes' => [['number' => 1, 'title' => 'At the gate', 'startedAt' => '2026-10-06T10:06:00.000000+02:00', 'kind' => 'scene', 'sceneType' => null, 'hook' => null]]]],
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

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedScenes(): iterable
    {
        yield 'unknown kind' => ['"kind": "interlude"', 'Stored campaign sessions: "kind" must be one of "scene", "hook".'];
        yield 'non-string Scene Type' => ['"sceneType": 7', 'Stored campaign sessions: "sceneType" must be a string.'];
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
