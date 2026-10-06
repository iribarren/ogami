<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Persistence;

use App\Play\Domain\Campaign\CampaignAlreadyExists;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Infrastructure\Persistence\Doctrine\CampaignSessionsType;
use App\Play\Infrastructure\Persistence\Doctrine\DoctrineCampaignRepository;
use App\Tests\Support\Play\CampaignRepositoryContract;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(DoctrineCampaignRepository::class)]
#[CoversClass(CampaignSessionsType::class)]
final class DoctrineCampaignRepositoryTest extends KernelTestCase
{
    use CampaignRepositoryContract;

    private DoctrineCampaignRepository $repository;
    private EntityManagerInterface $entityManager;

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
            [['number' => 1, 'startedAt' => '2026-10-06T10:05:00.000000+02:00', 'scenes' => [['number' => 1, 'title' => 'At the gate', 'startedAt' => '2026-10-06T10:06:00.000000+02:00']]]],
            json_decode($sessions, true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function savingACampaignThatWasNeverAddedFails(): void
    {
        $this->expectException(\LogicException::class);

        $this->repository->save($this->campaign('01890a5d-ac96-774b-bcce-b302099a8057', self::OWNER, '2026-10-06'));
    }
}
