<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Persistence;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\Journal\JournalEntryRepository;
use App\Play\Infrastructure\Persistence\Doctrine\DoctrineCampaignRepository;
use App\Play\Infrastructure\Persistence\Doctrine\DoctrineJournalEntryRepository;
use App\Play\Infrastructure\Persistence\Doctrine\JournalEntryContentType;
use App\Tests\Support\Play\JournalEntryRepositoryContract;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(DoctrineJournalEntryRepository::class)]
#[CoversClass(JournalEntryContentType::class)]
final class DoctrineJournalEntryRepositoryTest extends KernelTestCase
{
    use JournalEntryRepositoryContract;

    private DoctrineJournalEntryRepository $repository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->repository = $container->get(DoctrineJournalEntryRepository::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    protected function entries(): JournalEntryRepository
    {
        return $this->repository;
    }

    protected function givenCampaign(string $campaignId): void
    {
        self::getContainer()->get(DoctrineCampaignRepository::class)->add(Campaign::create(
            CampaignId::fromString($campaignId),
            '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f5001',
            'Campaign '.$campaignId,
            PinnedRelease::of('free-journal', 1, 'Free journal'),
            new \DateTimeImmutable('2026-10-05T10:00:00+00:00'),
        ));
    }

    protected function forgetLoaded(): void
    {
        $this->entityManager->clear();
    }

    #[Test]
    public function itIsThePortAdapter(): void
    {
        self::assertSame($this->repository, self::getContainer()->get(JournalEntryRepository::class));
    }

    #[Test]
    public function contentIsStoredAsJsonbAndTheTimeWithItsTimeZone(): void
    {
        $this->givenCampaign(self::CAMPAIGN);
        $this->repository->add($this->entry('01890a5d-ac96-774b-bcce-b302099a9001', self::CAMPAIGN, '2026-10-06 10:00:00'));

        $types = $this->entityManager->getConnection()->fetchAllKeyValue(
            "SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'play_journal_entry' AND column_name IN ('content', 'recorded_at') ORDER BY column_name",
        );

        self::assertSame(['content' => 'jsonb', 'recorded_at' => 'timestamp with time zone'], $types);
    }

    #[Test]
    public function anEntryOfAnUnknownCampaignIsRefusedByTheDatabase(): void
    {
        $this->expectException(ForeignKeyConstraintViolationException::class);

        $this->repository->add($this->entry('01890a5d-ac96-774b-bcce-b302099a9001', self::CAMPAIGN, '2026-10-06 10:00:00'));
    }
}
