<?php

declare(strict_types=1);

namespace App\Tests\Integration\Studio;

use App\Studio\Domain\Release\GameSystemRelease;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\ReleaseId;
use App\Studio\Infrastructure\Persistence\Doctrine\DoctrineGameSystemReleaseRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(DoctrineGameSystemReleaseRepository::class)]
final class DoctrineGameSystemReleaseRepositoryTest extends KernelTestCase
{
    private const string FIRST_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a51';
    private const string SECOND_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a52';
    private const string OTHER_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a53';

    private DoctrineGameSystemReleaseRepository $releases;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->releases = $container->get(DoctrineGameSystemReleaseRepository::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    #[Test]
    public function itRoundTripsAReleaseWithItsCanonicalContent(): void
    {
        $content = $this->contractDocExample();
        $this->releases->add(GameSystemRelease::publish(ReleaseId::fromString(self::FIRST_ID), $content, 1, new \DateTimeImmutable('2026-10-05T10:00:00')));
        $this->entityManager->clear();

        $release = $this->releases->ofId(ReleaseId::fromString(self::FIRST_ID));

        self::assertNotNull($release);
        self::assertSame('example-journal', $release->gameSystemKey());
        self::assertSame(1, $release->version());
        self::assertSame(1, $release->schemaVersion());
        self::assertSame($content->hash(), $release->contentHash());
        self::assertSame($content->hash(), $release->content()->hash());
        self::assertSame(
            json_encode($content->toArray(), \JSON_THROW_ON_ERROR),
            json_encode($release->content()->toArray(), \JSON_THROW_ON_ERROR),
        );
        self::assertEquals(new \DateTimeImmutable('2026-10-05T10:00:00'), $release->publishedAt());
    }

    #[Test]
    public function theContentIsStoredAsJsonb(): void
    {
        $this->releases->add(GameSystemRelease::publish(ReleaseId::fromString(self::FIRST_ID), $this->contractDocExample(), 1, new \DateTimeImmutable()));

        $type = $this->entityManager->getConnection()->fetchOne(
            "SELECT data_type FROM information_schema.columns WHERE table_name = 'studio_gamesystem_release' AND column_name = 'content'",
        );

        self::assertSame('jsonb', $type);
    }

    #[Test]
    public function itFindsTheLatestAndAGivenVersionPerGameSystem(): void
    {
        $this->releases->add(GameSystemRelease::publish(ReleaseId::fromString(self::FIRST_ID), $this->contractDocExample(), 1, new \DateTimeImmutable()));
        $this->releases->add(GameSystemRelease::publish(ReleaseId::fromString(self::SECOND_ID), $this->contractDocExample(), 2, new \DateTimeImmutable()));
        $this->releases->add(GameSystemRelease::publish(ReleaseId::fromString(self::OTHER_ID), $this->minimal('other-journal'), 7, new \DateTimeImmutable()));
        $this->entityManager->clear();

        self::assertSame(self::SECOND_ID, $this->releases->latestFor('example-journal')?->id()->toString());
        self::assertSame(self::FIRST_ID, $this->releases->get('example-journal', 1)?->id()->toString());
        self::assertSame(7, $this->releases->latestFor('other-journal')?->version());
    }

    #[Test]
    public function itFindsNothingForUnknownReleases(): void
    {
        self::assertNull($this->releases->latestFor('example-journal'));
        self::assertNull($this->releases->get('example-journal', 1));
        self::assertNull($this->releases->ofId(ReleaseId::fromString(self::FIRST_ID)));
        self::assertNull($this->releases->ofId(ReleaseId::fromString('not-a-uuid')));
    }

    #[Test]
    public function theDatabaseRejectsASecondReleaseWithTheSameVersion(): void
    {
        $this->releases->add(GameSystemRelease::publish(ReleaseId::fromString(self::FIRST_ID), $this->contractDocExample(), 1, new \DateTimeImmutable()));

        $this->expectException(UniqueConstraintViolationException::class);

        $this->releases->add(GameSystemRelease::publish(ReleaseId::fromString(self::SECOND_ID), $this->contractDocExample(), 1, new \DateTimeImmutable()));
    }

    private function contractDocExample(): ReleaseContent
    {
        $json = file_get_contents(\dirname(__DIR__, 2).'/Fixtures/Studio/releases/valid/contract-doc-example.json');
        self::assertIsString($json);
        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return ReleaseContent::fromArray($data);
    }

    private function minimal(string $key): ReleaseContent
    {
        return ReleaseContent::fromArray([
            'schemaVersion' => 1,
            'gameSystem' => ['key' => $key, 'name' => 'Other journal'],
            'oracles' => ['tables' => [], 'likelihood' => []],
            'flow' => ['steps' => []],
            'sheet' => [],
            'checks' => [],
        ]);
    }
}
