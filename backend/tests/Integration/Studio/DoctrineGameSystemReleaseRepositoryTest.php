<?php

declare(strict_types=1);

namespace App\Tests\Integration\Studio;

use App\Studio\Domain\Release\GameSystemRelease;
use App\Studio\Domain\Release\GameSystemReleaseAlreadyExists;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\ReleaseId;
use App\Studio\Infrastructure\Persistence\Doctrine\DoctrineGameSystemReleaseRepository;
use Doctrine\DBAL\Exception\DriverException;
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
    public function itRoundTripsTheLibrarySourcesOutsideTheHashAndKeepsNoneAsNull(): void
    {
        $content = $this->contractDocExample();
        $sources = [['section' => 'tags', 'key' => 'social', 'pack' => 'generic', 'hash' => str_repeat('ab', 32)]];
        $at = new \DateTimeImmutable('2026-10-18T10:00:00');
        $this->releases->add(GameSystemRelease::publish(ReleaseId::fromString(self::FIRST_ID), $content, 1, $at, null, $sources));
        $this->releases->add(GameSystemRelease::publish(ReleaseId::fromString(self::SECOND_ID), $content, 2, $at, null, []));
        $this->entityManager->clear();

        $with = $this->releases->ofId(ReleaseId::fromString(self::FIRST_ID));
        $without = $this->releases->ofId(ReleaseId::fromString(self::SECOND_ID));

        self::assertNotNull($with);
        self::assertNotNull($without);
        self::assertEquals($sources, $with->librarySources());
        self::assertNull($without->librarySources());
        self::assertSame($without->contentHash(), $with->contentHash());
        self::assertSame($content->hash(), $with->contentHash());
    }

    #[Test]
    public function aReleaseStoredBeforeLibrarySourcesReadsWithNone(): void
    {
        $content = $this->contractDocExample();
        // The columns before the library migration only: library_sources stays NULL.
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO studio_gamesystem_release (id, game_system_key, version, schema_version, content, content_hash, published_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [self::FIRST_ID, $content->gameSystemKey(), 1, 1, json_encode($content->toArray(), \JSON_THROW_ON_ERROR), $content->hash(), '2026-10-05 10:00:00'],
        );

        $release = $this->releases->ofId(ReleaseId::fromString(self::FIRST_ID));

        self::assertNotNull($release);
        self::assertNull($release->librarySources());
        self::assertSame($content->hash(), $release->contentHash());
    }

    #[Test]
    public function itRoundTripsTheWholeReleaseWithAndWithoutNotes(): void
    {
        $at = new \DateTimeImmutable('2026-10-16T09:30:00');
        $plain = GameSystemRelease::publish(ReleaseId::fromString(self::FIRST_ID), $this->contractDocExample(), 1, $at);
        $noted = GameSystemRelease::publish(ReleaseId::fromString(self::SECOND_ID), $this->contractDocExample(), 2, $at, "Fixed the oracles.\nÉ ñ 日本語");
        $this->releases->add($plain);
        $this->releases->add($noted);
        $this->entityManager->clear();

        foreach ([$plain, $noted] as $expected) {
            $actual = $this->releases->get('example-journal', $expected->version());
            self::assertNotNull($actual);
            self::assertSame(
                [$expected->id()->toString(), $expected->gameSystemKey(), $expected->schemaVersion(), $expected->contentHash(), $expected->notes()],
                [$actual->id()->toString(), $actual->gameSystemKey(), $actual->schemaVersion(), $actual->contentHash(), $actual->notes()],
            );
            self::assertEquals($expected->publishedAt(), $actual->publishedAt());
            self::assertSame(json_encode($expected->content()->toArray(), \JSON_THROW_ON_ERROR), json_encode($actual->content()->toArray(), \JSON_THROW_ON_ERROR));
        }

        self::assertSame("Fixed the oracles.\nÉ ñ 日本語", $this->releases->get('example-journal', 2)?->notes());
    }

    #[Test]
    public function aReleaseStoredBeforeNotesExistedReadsWithoutNotes(): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO studio_gamesystem_release (id, game_system_key, version, schema_version, content, content_hash, published_at) VALUES (:id, :key, 1, 1, :content, :hash, NOW())',
            ['id' => self::FIRST_ID, 'key' => 'example-journal', 'content' => json_encode($this->contractDocExample()->toArray(), \JSON_THROW_ON_ERROR), 'hash' => $this->contractDocExample()->hash()],
        );

        $release = $this->releases->get('example-journal', 1);

        self::assertNotNull($release);
        self::assertNull($release->notes());
        self::assertSame($this->contractDocExample()->hash(), $release->contentHash());
    }

    #[Test]
    public function theDatabaseRefusesBlankOrTooLongNotesLikeTheDomain(): void
    {
        foreach (['', "  \n", " \t\f\v", str_repeat('é', 2001)] as $notes) {
            $this->entityManager->getConnection()->executeStatement('SAVEPOINT notes');

            try {
                $this->entityManager->getConnection()->executeStatement(
                    'INSERT INTO studio_gamesystem_release (id, game_system_key, version, schema_version, content, content_hash, published_at, notes) VALUES (:id, :key, 1, 1, :content, :hash, NOW(), :notes)',
                    ['id' => self::FIRST_ID, 'key' => 'example-journal', 'content' => '{}', 'hash' => str_repeat('a', 64), 'notes' => $notes],
                );
                self::fail('The notes were stored.');
            } catch (DriverException $refusal) {
                self::assertStringContainsString('chk_studio_gamesystem_release_notes', $refusal->getMessage());
                $this->entityManager->getConnection()->executeStatement('ROLLBACK TO SAVEPOINT notes');
            }
        }

        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO studio_gamesystem_release (id, game_system_key, version, schema_version, content, content_hash, published_at, notes) VALUES (:id, :key, 1, 1, :content, :hash, NOW(), :notes)',
            ['id' => self::FIRST_ID, 'key' => 'example-journal', 'content' => '{}', 'hash' => str_repeat('a', 64), 'notes' => str_repeat('é', 2000)],
        );
        self::assertSame(2000, mb_strlen((string) $this->releases->get('example-journal', 1)?->notes()));
    }

    #[Test]
    public function notesWithFormFeedsAndUnicodeSpacesRoundTrip(): void
    {
        foreach (["Fixed\fthings", "Fixed\u{00A0}the\u{2003}things", "\u{00A0}x\u{3000}"] as $version => $notes) {
            $this->releases->add(GameSystemRelease::publish(ReleaseId::fromString(\sprintf('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a6%d', $version)), $this->contractDocExample(), $version + 1, new \DateTimeImmutable(), $notes));
            $this->entityManager->clear();

            self::assertSame($notes, $this->releases->get('example-journal', $version + 1)?->notes());
        }
    }

    #[Test]
    public function itListsEveryReleaseOfAKeyNewestFirst(): void
    {
        $this->releases->add(GameSystemRelease::publish(ReleaseId::fromString(self::FIRST_ID), $this->contractDocExample(), 1, new \DateTimeImmutable()));
        $this->releases->add(GameSystemRelease::publish(ReleaseId::fromString(self::OTHER_ID), $this->minimal('other-journal'), 5, new \DateTimeImmutable()));
        $this->releases->add(GameSystemRelease::publish(ReleaseId::fromString(self::SECOND_ID), $this->contractDocExample(), 2, new \DateTimeImmutable()));
        $this->entityManager->clear();

        self::assertSame([self::SECOND_ID, self::FIRST_ID], array_map(static fn (GameSystemRelease $release): string => $release->id()->toString(), $this->releases->allFor('example-journal')));
        self::assertSame([], $this->releases->allFor('nothing'));
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
    public function itListsTheLatestReleaseOfEachGameSystemOrderedByKey(): void
    {
        $this->releases->add(GameSystemRelease::publish(ReleaseId::fromString(self::OTHER_ID), $this->minimal('other-journal'), 7, new \DateTimeImmutable()));
        $this->releases->add(GameSystemRelease::publish(ReleaseId::fromString(self::FIRST_ID), $this->contractDocExample(), 1, new \DateTimeImmutable()));
        $this->releases->add(GameSystemRelease::publish(ReleaseId::fromString(self::SECOND_ID), $this->contractDocExample(), 2, new \DateTimeImmutable()));
        $this->entityManager->clear();

        $latest = $this->releases->latestOfEachKey();

        self::assertSame(
            [['example-journal', 2, self::SECOND_ID], ['other-journal', 7, self::OTHER_ID]],
            array_map(static fn (GameSystemRelease $release): array => [$release->gameSystemKey(), $release->version(), $release->id()->toString()], $latest),
        );
    }

    #[Test]
    public function itFindsNothingForUnknownReleases(): void
    {
        self::assertNull($this->releases->latestFor('example-journal'));
        self::assertSame([], $this->releases->latestOfEachKey());
        self::assertNull($this->releases->get('example-journal', 1));
        self::assertNull($this->releases->ofId(ReleaseId::fromString(self::FIRST_ID)));
        self::assertNull($this->releases->ofId(ReleaseId::fromString('not-a-uuid')));
    }

    #[Test]
    public function theDatabaseRejectsASecondReleaseWithTheSameVersion(): void
    {
        $this->releases->add(GameSystemRelease::publish(ReleaseId::fromString(self::FIRST_ID), $this->contractDocExample(), 1, new \DateTimeImmutable()));

        try {
            $this->releases->add(GameSystemRelease::publish(ReleaseId::fromString(self::SECOND_ID), $this->contractDocExample(), 1, new \DateTimeImmutable()));
            self::fail('Two releases share a version.');
        } catch (GameSystemReleaseAlreadyExists $exception) {
            self::assertSame('Release example-journal v1 already exists.', $exception->getMessage());
            self::assertInstanceOf(UniqueConstraintViolationException::class, $exception->getPrevious());
        }
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
