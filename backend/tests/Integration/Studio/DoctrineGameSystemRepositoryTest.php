<?php

declare(strict_types=1);

namespace App\Tests\Integration\Studio;

use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\GameSystemId;
use App\Studio\Domain\GameSystem\GameSystemKeyTaken;
use App\Studio\Domain\GameSystem\GameSystemKind;
use App\Studio\Domain\GameSystem\GameSystemMetadata;
use App\Studio\Domain\GameSystem\GameSystemModifiedConcurrently;
use App\Studio\Domain\GameSystem\LibraryImport;
use App\Studio\Domain\GameSystem\LibraryItemRef;
use App\Studio\Domain\GameSystem\LibrarySection;
use App\Studio\Domain\GameSystem\NarrativeSection;
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
        $gameSystem = GameSystem::createFromRelease(GameSystemId::fromString(self::FIRST_ID), 'my-copy', 'My copy', null, self::OWNER, $source, new \DateTimeImmutable('2026-10-15T10:00:00'));
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
    public function aLibraryPackRoundTripsWithItsKind(): void
    {
        $pack = $this->pack(self::FIRST_ID, 'names');
        $this->gameSystems->add($pack);
        $this->entityManager->clear();

        $loaded = $this->gameSystems->ofKey('names');

        self::assertNotNull($loaded);
        self::assertNotSame($pack, $loaded);
        self::assertEquals($pack, $loaded);
        self::assertSame(GameSystemKind::LibraryPack, $loaded->kind());
        self::assertSame([], $loaded->libraryImports());
    }

    #[Test]
    public function aDraftRoundTripsItsLibraryImportsAndTheirChanges(): void
    {
        $pack = $this->pack(self::FIRST_ID, 'names');
        $draft = $this->blank(self::SECOND_ID, 'my-game', self::OWNER);
        $draft->importFromLibrary($pack, [new LibraryItemRef(LibrarySection::SceneTypes, 'tavern')], new \DateTimeImmutable('2026-10-18T12:00:00'));
        $this->gameSystems->add($pack);
        $this->gameSystems->add($draft);
        $this->entityManager->clear();

        $loaded = $this->gameSystems->ofKey('my-game');

        self::assertNotNull($loaded);
        self::assertNotSame($draft, $loaded);
        // JSONB does not keep the order of an object's keys; the whole aggregate is still equal.
        self::assertEquals($draft, $loaded);
        self::assertSame(['oracles/names', 'tags/social', 'scene-types/tavern'], array_map(static fn (LibraryImport $import): string => $import->ref()->id(), $loaded->libraryImports()));
        self::assertSame(ReleaseContent::itemHash(['key' => 'social', 'label' => 'Social']), $loaded->libraryImports()[1]->hash);

        // A later command that drops an import is saved too: the column is replaced as a whole.
        $loaded->deleteNarrativeItem(NarrativeSection::SceneTypes, 'tavern', new \DateTimeImmutable('2026-10-18T13:00:00'));
        $loaded->detachLibraryImport(LibrarySection::Tags, 'social', new \DateTimeImmutable('2026-10-18T13:00:00'));
        $this->gameSystems->save($loaded);
        $this->entityManager->clear();

        $reloaded = $this->gameSystems->ofKey('my-game');

        self::assertNotNull($reloaded);
        self::assertEquals($loaded, $reloaded);
        self::assertSame(['oracles/names'], array_map(static fn (LibraryImport $import): string => $import->ref()->id(), $reloaded->libraryImports()));
    }

    #[Test]
    public function aMalformedStoredImportsRowStillLoadsAndFailsClosedWhenItsImportsAreRead(): void
    {
        $this->gameSystems->add($this->blank(self::FIRST_ID, 'corrupt', self::OWNER));
        $this->gameSystems->add($this->blank(self::SECOND_ID, 'fine', self::OWNER));
        $this->entityManager->getConnection()->executeStatement("UPDATE studio_gamesystem SET library_imports = '[{\"section\": \"tags\"}]' WHERE game_system_key = 'corrupt'");
        $this->entityManager->clear();

        // The reads do not parse the imports, so one corrupt row cannot break a list or a detail.
        self::assertCount(2, $this->gameSystems->ofOwner(self::OWNER));
        $corrupt = $this->gameSystems->ofKey('corrupt');
        self::assertInstanceOf(GameSystem::class, $corrupt);

        // Whatever acts on the imports fails closed.
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains('library import 0');
        $corrupt->libraryImports();
    }

    #[Test]
    public function aPackWithMalformedStoredImportsStillLists(): void
    {
        $this->gameSystems->add($this->pack(self::FIRST_ID, 'names'));
        $this->entityManager->getConnection()->executeStatement("UPDATE studio_gamesystem SET library_imports = '[{\"section\": \"tags\"}]' WHERE game_system_key = 'names'");
        $this->entityManager->clear();

        self::assertSame(['names'], array_map(static fn (GameSystem $pack): string => $pack->key(), $this->gameSystems->libraryPacks()));
    }

    #[Test]
    public function aDraftRoundTripsAsAGameSystemWithNoImports(): void
    {
        $this->gameSystems->add($this->blank(self::FIRST_ID, 'plain', self::OWNER));
        $this->entityManager->clear();

        $loaded = $this->gameSystems->ofKey('plain');

        self::assertNotNull($loaded);
        self::assertSame(GameSystemKind::GameSystem, $loaded->kind());
        self::assertSame([], $loaded->libraryImports());
    }

    #[Test]
    public function aRowStoredBeforeLibraryPacksReadsAsAGameSystemWithNoImports(): void
    {
        $connection = $this->entityManager->getConnection();
        $document = json_encode(GameSystem::createBlank(GameSystemId::fromString(self::FIRST_ID), 'legacy', 'Legacy', null, self::OWNER, new \DateTimeImmutable('2026-10-15T10:00:00'))->document(), \JSON_THROW_ON_ERROR);
        // The columns of the first migration only: kind and library_imports take their defaults.
        $connection->executeStatement(
            'INSERT INTO studio_gamesystem (id, game_system_key, owner_id, document, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [self::FIRST_ID, 'legacy', self::OWNER, $document, '2026-10-15 10:00:00', '2026-10-15 10:00:00'],
        );

        $loaded = $this->gameSystems->ofKey('legacy');

        self::assertNotNull($loaded);
        self::assertSame(GameSystemKind::GameSystem, $loaded->kind());
        self::assertFalse($loaded->isLibraryPack());
        self::assertSame([], $loaded->libraryImports());
    }

    #[Test]
    public function itListsEveryLibraryPackOfAnyOwnerAndNoDraft(): void
    {
        $now = new \DateTimeImmutable('2026-10-18T10:00:00');
        $this->gameSystems->add(GameSystem::createLibraryPack(GameSystemId::fromString(self::FIRST_ID), 'mine', self::OWNER, 'Mine', null, $now));
        $this->gameSystems->add(GameSystem::createLibraryPack(GameSystemId::fromString(self::SECOND_ID), 'theirs', 'user-2', 'Theirs', null, $now));
        $this->gameSystems->add($this->blank('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a63', 'draft', self::OWNER));
        $this->entityManager->clear();

        $keys = array_map(static fn (GameSystem $gameSystem): string => $gameSystem->key(), $this->gameSystems->libraryPacks());
        sort($keys);

        self::assertSame(['mine', 'theirs'], $keys);
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
    public function aPointBudgetSaveRoundTripsTheWholeDocument(): void
    {
        $source = ReleaseContent::fromArray(ReleaseArrays::fixture('valid/v2-minimal'))->toArray();
        $this->gameSystems->add(GameSystem::createFromRelease(GameSystemId::fromString(self::FIRST_ID), 'budgeted', 'Budgeted', null, self::OWNER, $source, new \DateTimeImmutable('2026-10-15T10:00:00')));
        $this->entityManager->clear();
        $loaded = $this->gameSystems->ofKey('budgeted');
        self::assertNotNull($loaded);
        $now = new \DateTimeImmutable('2026-10-16T10:00:00');

        $loaded->saveSheetField(null, ['key' => 'str', 'label' => 'Strength', 'type' => 'number', 'min' => 1, 'max' => 5], $now);
        $loaded->saveSheetField(null, ['key' => 'lore', 'label' => 'Lore', 'type' => 'number', 'min' => 0, 'max' => 5], $now);
        $loaded->saveSheetPointBudgetEntry('attributes', ['label' => 'Attribute points', 'total' => 12, 'fields' => ['str']], $now);
        $loaded->saveSheetPointBudgetEntry('skills', ['label' => 'Skill points', 'total' => 8, 'fields' => ['lore'], 'max' => 3], $now);
        $this->gameSystems->save($loaded);
        $this->entityManager->clear();

        $reloaded = $this->gameSystems->ofKey('budgeted');
        self::assertNotNull($reloaded);
        self::assertEquals($loaded, $reloaded);
        self::assertSame(['attributes', 'skills'], array_column((array) ((array) $reloaded->document()['sheet'])['pointBudgets'], 'key'));
        ReleaseContent::checkItemRules($reloaded->document());
    }

    #[Test]
    public function aNarrativeItemSaveRoundTripsTheWholeDocument(): void
    {
        $source = ReleaseContent::fromArray(ReleaseArrays::fixture('valid/v2-every-part'))->toArray();
        $this->gameSystems->add(GameSystem::createFromRelease(GameSystemId::fromString(self::FIRST_ID), 'narrated', 'Narrated', null, self::OWNER, $source, new \DateTimeImmutable('2026-10-15T10:00:00')));
        $this->entityManager->clear();
        $loaded = $this->gameSystems->ofKey('narrated');
        self::assertNotNull($loaded);
        $now = new \DateTimeImmutable('2026-10-16T10:00:00');

        $loaded->saveNarrativeItem(NarrativeSection::Tags, null, ['key' => 'gang', 'label' => 'Gang'], $now);
        $loaded->saveNarrativeItem(NarrativeSection::Trackers, 'edge', ['key' => 'edge', 'name' => 'Advantage', 'kind' => 'counter', 'min' => 0, 'max' => 3, 'initial' => 0], $now);
        $loaded->deleteNarrativeItem(NarrativeSection::FactSlots, 'target', $now);
        $this->gameSystems->save($loaded);
        $this->entityManager->clear();

        $reloaded = $this->gameSystems->ofKey('narrated');
        self::assertNotNull($reloaded);
        self::assertEquals($loaded, $reloaded);
        self::assertSame([['key' => 'gang', 'label' => 'Gang']], $reloaded->document()['tags']);
        ReleaseContent::checkItemRules($reloaded->document());
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

    /**
     * A pack with a Scene Type that needs the table "names" and the tag "social".
     */
    private function pack(string $id, string $key): GameSystem
    {
        $now = new \DateTimeImmutable('2026-10-18T11:00:00');
        $pack = GameSystem::createLibraryPack(GameSystemId::fromString($id), $key, self::OWNER, ucfirst($key), 'Generic', new \DateTimeImmutable('2026-10-18T10:00:00'));
        $pack->saveOracleTable(null, ['key' => 'names', 'name' => 'Names', 'dice' => '1d6', 'entries' => [['min' => 1, 'max' => 6, 'text' => 'Aldric']]], $now);
        $pack->saveNarrativeItem(NarrativeSection::Tags, null, ['key' => 'social', 'label' => 'Social'], $now);
        $pack->saveNarrativeItem(NarrativeSection::SceneTypes, null, [
            'key' => 'tavern', 'name' => 'Tavern', 'purpose' => 'Hear rumours.', 'oracles' => ['names'], 'setup' => [], 'play' => [],
            'closing' => [['key' => 'meet', 'kind' => 'choice', 'title' => 'Meet?', 'options' => [['key' => 'yes', 'label' => 'Yes', 'effects' => [['kind' => 'createNpc', 'name' => 'Innkeeper', 'tags' => ['social']]]], ['key' => 'no', 'label' => 'No']], 'skip' => 'no']],
        ], $now);

        return $pack;
    }
}
