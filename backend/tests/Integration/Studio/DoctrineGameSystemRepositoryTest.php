<?php

declare(strict_types=1);

namespace App\Tests\Integration\Studio;

use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\GameSystemId;
use App\Studio\Domain\GameSystem\GameSystemKeyTaken;
use App\Studio\Domain\GameSystem\GameSystemMetadata;
use App\Studio\Domain\GameSystem\GameSystemModifiedConcurrently;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Infrastructure\Persistence\Doctrine\DoctrineGameSystemRepository;
use App\Tests\Support\Studio\ReleaseArrays;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(DoctrineGameSystemRepository::class)]
final class DoctrineGameSystemRepositoryTest extends KernelTestCase
{
    private const string FIRST_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a61';
    private const string SECOND_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a62';
    private const string OWNER = 'user-1';

    private DoctrineGameSystemRepository $gameSystems;
    private EntityManagerInterface $entityManager;
    private ?EntityManager $otherEntityManager = null;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->gameSystems = new DoctrineGameSystemRepository($this->entityManager);
    }

    #[Test]
    public function itRoundTripsTheWholeAggregate(): void
    {
        $source = ReleaseContent::fromArray(ReleaseArrays::fixture('valid/v2-every-part'))->toArray();
        $gameSystem = GameSystem::createFromRelease(GameSystemId::fromString(self::FIRST_ID), 'my-copy', 'My copy', self::OWNER, $source, new \DateTimeImmutable('2026-10-15T10:00:00'));
        $gameSystem->editMetadata(GameSystemMetadata::of('My copy', 'Text', ['notice' => 'By me', 'license' => 'CC-BY'], 'noir'), new \DateTimeImmutable('2026-10-15T11:00:00'));
        $this->gameSystems->add($gameSystem);
        $this->entityManager->clear();

        $loaded = $this->gameSystems->ofKey('my-copy');

        self::assertNotNull($loaded);
        self::assertNotSame($gameSystem, $loaded);
        self::assertEquals($gameSystem, $loaded);
        // JSONB does not keep the order of an object's keys; the canonical content, and so its hash, is the same.
        self::assertSame(ReleaseContent::fromArray($gameSystem->document())->hash(), ReleaseContent::fromArray($loaded->document())->hash());
    }

    #[Test]
    public function aBlankDraftRoundTripsWithItsEmptySheet(): void
    {
        $gameSystem = GameSystem::createBlank(GameSystemId::fromString(self::FIRST_ID), 'blank', 'Blank', null, self::OWNER, new \DateTimeImmutable('2026-10-15T10:00:00'));
        $this->gameSystems->add($gameSystem);
        $this->entityManager->clear();

        self::assertEquals($gameSystem, $this->gameSystems->ofKey('blank'));
    }

    #[Test]
    public function timestampsAreStoredWithSecondPrecision(): void
    {
        $this->gameSystems->add($this->blank(self::FIRST_ID, 'precise', self::OWNER, '2026-10-15T10:00:00.987654'));
        $this->entityManager->clear();

        $loaded = $this->gameSystems->ofKey('precise');

        self::assertNotNull($loaded);
        self::assertSame('2026-10-15T10:00:00.000000', $loaded->createdAt()->format('Y-m-d\\TH:i:s.u'));
        self::assertSame('2026-10-15T10:00:00.000000', $loaded->updatedAt()->format('Y-m-d\\TH:i:s.u'));
    }

    #[Test]
    public function draftsUpdatedInTheSameSecondAreOrderedByKey(): void
    {
        $this->gameSystems->add($this->blank(self::FIRST_ID, 'zeta', self::OWNER, '2026-10-15T10:00:00.900'));
        $this->gameSystems->add($this->blank(self::SECOND_ID, 'alpha', self::OWNER, '2026-10-15T10:00:00.100'));
        $this->entityManager->clear();

        self::assertSame(['alpha', 'zeta'], array_map(static fn (GameSystem $gameSystem): string => $gameSystem->key(), $this->gameSystems->ofOwner(self::OWNER)));
    }

    #[Test]
    public function theDocumentIsStoredAsJsonb(): void
    {
        $this->gameSystems->add($this->blank(self::FIRST_ID, 'blank', self::OWNER));

        $type = $this->entityManager->getConnection()->fetchOne(
            "SELECT data_type FROM information_schema.columns WHERE table_name = 'studio_gamesystem' AND column_name = 'document'",
        );

        self::assertSame('jsonb', $type);
    }

    #[Test]
    public function itFindsNothingForAnUnknownKey(): void
    {
        self::assertNull($this->gameSystems->ofKey('missing'));
        self::assertSame([], $this->gameSystems->ofOwner(self::OWNER));
    }

    #[Test]
    public function itListsAnOwnersGameSystemsNewestUpdatedFirst(): void
    {
        $older = $this->blank(self::FIRST_ID, 'older', self::OWNER, '2026-10-15T10:00:00');
        $newer = $this->blank(self::SECOND_ID, 'newer', self::OWNER, '2026-10-15T12:00:00');
        $others = $this->blank('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a63', 'others', 'user-2');
        foreach ([$older, $newer, $others] as $gameSystem) {
            $this->gameSystems->add($gameSystem);
        }

        $this->entityManager->clear();

        self::assertSame(['newer', 'older'], array_map(static fn (GameSystem $gameSystem): string => $gameSystem->key(), $this->gameSystems->ofOwner(self::OWNER)));
    }

    #[Test]
    public function theDatabaseRejectsASecondGameSystemWithTheSameKey(): void
    {
        $this->gameSystems->add($this->blank(self::FIRST_ID, 'taken', self::OWNER));

        $this->expectException(GameSystemKeyTaken::class);
        $this->expectExceptionMessageIsOrContains('The GameSystem key "taken" is already taken.');

        $this->gameSystems->add($this->blank(self::SECOND_ID, 'taken', 'user-2'));
    }

    #[Test]
    public function savingKeepsTheChanges(): void
    {
        $this->gameSystems->add($this->blank(self::FIRST_ID, 'edited', self::OWNER));
        $this->entityManager->clear();
        $loaded = $this->gameSystems->ofKey('edited');
        self::assertNotNull($loaded);

        $loaded->editMetadata(GameSystemMetadata::of('Renamed', null, null, 'neon'), new \DateTimeImmutable('2026-10-16T10:00:00'));
        $this->gameSystems->save($loaded);
        $this->entityManager->clear();

        self::assertEquals($loaded, $this->gameSystems->ofKey('edited'));
        self::assertSame('Renamed', $this->gameSystems->ofKey('edited')?->name());
    }

    #[Test]
    public function savingAStaleCopyIsRefused(): void
    {
        $this->gameSystems->add($this->blank(self::FIRST_ID, 'raced', self::OWNER));
        $this->entityManager->clear();
        $mine = $this->gameSystems->ofKey('raced');
        $theirs = $this->elsewhere()->ofKey('raced');
        self::assertNotNull($mine);
        self::assertNotNull($theirs);

        $theirs->editMetadata(GameSystemMetadata::of('Theirs', null, null, null), new \DateTimeImmutable('2026-10-16T10:00:00'));
        $this->elsewhere()->save($theirs);
        $mine->editMetadata(GameSystemMetadata::of('Mine', null, null, null), new \DateTimeImmutable('2026-10-16T11:00:00'));

        try {
            $this->gameSystems->save($mine);
            self::fail('The stale copy was saved.');
        } catch (GameSystemModifiedConcurrently) {
            // expected: the other request's save won
        }

        $stored = $this->entityManager->getConnection()->fetchAssociative("SELECT updated_at, document->'gameSystem'->>'name' AS name FROM studio_gamesystem WHERE game_system_key = 'raced'");
        self::assertSame('Theirs', $stored['name'] ?? null);
        self::assertSame('2026-10-16 10:00:00', $stored['updated_at'] ?? null);
    }

    #[Test]
    public function savingAGameSystemItDidNotLoadIsALogicError(): void
    {
        $this->expectException(\LogicException::class);

        $this->gameSystems->save($this->blank(self::FIRST_ID, 'stranger', self::OWNER));
    }

    #[Test]
    public function itRemovesAGameSystem(): void
    {
        $gameSystem = $this->blank(self::FIRST_ID, 'gone', self::OWNER);
        $this->gameSystems->add($gameSystem);

        $this->gameSystems->remove($gameSystem);
        $this->entityManager->clear();

        self::assertNull($this->gameSystems->ofKey('gone'));
    }

    private function elsewhere(): DoctrineGameSystemRepository
    {
        $this->otherEntityManager ??= new EntityManager(
            $this->entityManager->getConnection(),
            $this->entityManager->getConfiguration(),
        );

        return new DoctrineGameSystemRepository($this->otherEntityManager);
    }

    private function blank(string $id, string $key, string $owner, string $now = '2026-10-15T10:00:00'): GameSystem
    {
        return GameSystem::createBlank(GameSystemId::fromString($id), $key, ucfirst($key), 'A test', $owner, new \DateTimeImmutable($now));
    }
}
