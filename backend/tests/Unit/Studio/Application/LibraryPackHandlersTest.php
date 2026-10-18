<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Application;

use App\Studio\Application\CreateGameSystem;
use App\Studio\Application\CreateGameSystemHandler;
use App\Studio\Application\CreateLibraryPack;
use App\Studio\Application\CreateLibraryPackHandler;
use App\Studio\Application\DiscardGameSystem;
use App\Studio\Application\DiscardGameSystemHandler;
use App\Studio\Application\GameSystemSummary;
use App\Studio\Application\GetLibraryPack;
use App\Studio\Application\GetLibraryPackHandler;
use App\Studio\Application\LibraryImportStandings;
use App\Studio\Application\LibraryPackCatalog;
use App\Studio\Application\LibraryPackNotFound;
use App\Studio\Application\LibraryPacks;
use App\Studio\Application\LibraryPackSummary;
use App\Studio\Application\ListLibraryPacks;
use App\Studio\Application\ListLibraryPacksHandler;
use App\Studio\Application\ListMyGameSystems;
use App\Studio\Application\ListMyGameSystemsHandler;
use App\Studio\Application\OwnedGameSystems;
use App\Studio\Application\PublishGameSystemDraft;
use App\Studio\Application\PublishGameSystemDraftHandler;
use App\Studio\Application\ReleasePublisher;
use App\Studio\Application\RestoreGameSystemRelease;
use App\Studio\Application\RestoreGameSystemReleaseHandler;
use App\Studio\Domain\GameSystem\GameSystemKeyTaken;
use App\Studio\Domain\GameSystem\GameSystemKind;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\GameSystem\InvalidGameSystemMetadata;
use App\Studio\Domain\GameSystem\LibraryPackRefuses;
use App\Studio\Domain\GameSystem\LibrarySection;
use App\Studio\Domain\GameSystem\NarrativeSection;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\ReleaseId;
use App\Tests\Support\Studio\FixedClock;
use App\Tests\Support\Studio\InMemoryGameSystemReleaseRepository;
use App\Tests\Support\Studio\InMemoryGameSystemRepository;
use App\Tests\Support\Studio\ReleaseArrays;
use App\Tests\Support\Studio\SequentialGameSystemIdGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CreateLibraryPackHandler::class)]
#[CoversClass(ListLibraryPacksHandler::class)]
#[CoversClass(GetLibraryPackHandler::class)]
#[CoversClass(ListMyGameSystemsHandler::class)]
#[CoversClass(PublishGameSystemDraftHandler::class)]
#[CoversClass(RestoreGameSystemReleaseHandler::class)]
final class LibraryPackHandlersTest extends TestCase
{
    private InMemoryGameSystemRepository $gameSystems;
    private InMemoryGameSystemReleaseRepository $releases;
    private FixedClock $clock;
    private SequentialGameSystemIdGenerator $ids;

    protected function setUp(): void
    {
        $this->gameSystems = new InMemoryGameSystemRepository();
        $this->releases = new InMemoryGameSystemReleaseRepository();
        $this->clock = new FixedClock('2026-10-18T10:00:00+00:00');
        $this->ids = new SequentialGameSystemIdGenerator();
    }

    #[Test]
    public function aBlankPackIsCreatedForItsOwner(): void
    {
        $this->createPack('names', 'Names', 'ann', 'Generic tables');

        $pack = $this->gameSystems->ofKey('names');
        self::assertNotNull($pack);
        self::assertSame([GameSystemKind::LibraryPack, 'Names', 2], [$pack->kind(), $pack->name(), $pack->schemaVersion()]);
        self::assertTrue($pack->isOwnedBy('ann'));
        self::assertEquals($this->clock->now(), $pack->createdAt());
    }

    #[Test]
    public function aPackKeyTakenByADraftAPackOrAReleaseIsRefused(): void
    {
        $this->createDraft('a-draft', 'ann');
        $this->createPack('a-pack', 'A pack', 'ann');
        $this->publishRelease(ReleaseArrays::fixture('valid/minimal'));

        foreach (['a-draft', 'a-pack', 'minimal'] as $key) {
            try {
                $this->createPack($key, 'Again', 'bob');
                self::fail('The pack was created.');
            } catch (GameSystemKeyTaken) {
                self::assertNotTrue($this->gameSystems->ofKey($key)?->isOwnedBy('bob'));
            }
        }

        self::assertCount(2, $this->gameSystems->ofOwner('ann'));
        self::assertSame([], $this->gameSystems->ofOwner('bob'));
    }

    #[Test]
    public function aKeyTakenByAPackIsRefusedToADraftToo(): void
    {
        $this->createPack('shared-key', 'Pack', 'ann');

        $this->expectException(GameSystemKeyTaken::class);

        $this->createDraft('shared-key', 'bob');
    }

    #[Test]
    public function anInvalidKeyOrNameCreatesNoPack(): void
    {
        foreach ([['Not A Key', 'Names'], ['names', '  ']] as [$key, $name]) {
            try {
                $this->createPack($key, $name, 'ann');
                self::fail('The pack was created.');
            } catch (InvalidGameSystemMetadata) {
            }
        }

        self::assertSame([], $this->gameSystems->libraryPacks());
    }

    #[Test]
    public function theLibraryListsEveryOwnersPacksMineFirstThenByNameWithItemCounts(): void
    {
        $this->createPack('zeta', 'Zeta', 'ann', 'Mine, last by name');
        $this->createPack('alpha', 'alpha', 'bob', 'Theirs');
        $this->createPack('beta', 'Beta', 'ann');
        $this->createDraft('a-draft', 'ann');
        $beta = $this->gameSystems->ofKey('beta');
        self::assertNotNull($beta);
        $beta->saveNarrativeItem(NarrativeSection::Tags, null, ['key' => 'gang', 'label' => 'Gang'], $this->clock->now());
        $beta->saveNarrativeItem(NarrativeSection::Tags, null, ['key' => 'crew', 'label' => 'Crew'], $this->clock->now());
        $beta->saveOracleTable(null, ['key' => 'npc-name', 'name' => 'NPC name', 'dice' => '1d6', 'entries' => [['min' => 1, 'max' => 6, 'text' => 'Aldric']]], $this->clock->now());

        $list = $this->library('ann');

        self::assertSame(['beta', 'zeta', 'alpha'], array_map(static fn (LibraryPackSummary $pack): string => $pack->key, $list));
        self::assertSame([true, true, false], array_map(static fn (LibraryPackSummary $pack): bool => $pack->mine, $list));
        self::assertSame(['oracleTables' => 1, 'likelihoodOracles' => 0, 'trackers' => 0, 'tags' => 2, 'factSlots' => 0, 'sceneTypes' => 0], $list[0]->itemCounts);
        self::assertSame([null, 'Mine, last by name', 'Theirs'], array_map(static fn (LibraryPackSummary $pack): ?string => $pack->description, $list));
        self::assertEquals($this->clock->now(), $list[1]->updatedAt);
        self::assertSame(['alpha', 'beta', 'zeta'], array_map(static fn (LibraryPackSummary $pack): string => $pack->key, $this->library('bob')));
        self::assertSame([true, false, false], array_map(static fn (LibraryPackSummary $pack): bool => $pack->mine, $this->library('bob')));
    }

    #[Test]
    public function theLibraryOfSomeoneWithoutPacksStillShowsOthersPacks(): void
    {
        $this->createPack('names', 'Names', 'ann');

        $list = $this->library('cleo');

        self::assertSame(['names'], array_map(static fn (LibraryPackSummary $pack): string => $pack->key, $list));
        self::assertFalse($list[0]->mine);
    }

    #[Test]
    public function theGameSystemListExcludesPacks(): void
    {
        $this->createPack('names', 'Names', 'ann');
        $this->createDraft('a-draft', 'ann');

        $drafts = (new ListMyGameSystemsHandler($this->gameSystems, $this->releases, new LibraryImportStandings(new LibraryPacks($this->gameSystems))))(new ListMyGameSystems('ann'));

        self::assertSame(['a-draft'], array_map(static fn (GameSystemSummary $summary): string => $summary->key, $drafts));
    }

    #[Test]
    public function aPackIsNeverPublishedOrRestored(): void
    {
        $this->createPack('names', 'Names', 'ann');
        $before = $this->gameSystems->ofKey('names')?->document();

        try {
            (new PublishGameSystemDraftHandler($this->owned(), new ReleasePublisher($this->releases, $this->clock), $this->releases))(new PublishGameSystemDraft('ann', 'names', 'release-1', null));
            self::fail('The pack was published.');
        } catch (LibraryPackRefuses $refusal) {
            self::assertStringContainsString('be published', $refusal->getMessage());
        }

        try {
            (new RestoreGameSystemReleaseHandler($this->owned(), $this->gameSystems, $this->releases, $this->clock))(new RestoreGameSystemRelease('ann', 'names', 1));
            self::fail('The pack was restored.');
        } catch (LibraryPackRefuses $refusal) {
            self::assertStringContainsString('restore a release', $refusal->getMessage());
        }

        self::assertSame(0, $this->releases->count());
        self::assertSame($before, $this->gameSystems->ofKey('names')?->document());
        self::assertSame(1, $this->gameSystems->ofKey('names')?->version());
    }

    #[Test]
    public function anotherOwnersPackBehavesAsMissingForPublishAndRestore(): void
    {
        $this->createPack('names', 'Names', 'ann');

        try {
            (new PublishGameSystemDraftHandler($this->owned(), new ReleasePublisher($this->releases, $this->clock), $this->releases))(new PublishGameSystemDraft('bob', 'names', 'release-1', null));
            self::fail('Another owner published the pack.');
        } catch (GameSystemNotFound) {
        }

        $this->expectException(GameSystemNotFound::class);

        (new RestoreGameSystemReleaseHandler($this->owned(), $this->gameSystems, $this->releases, $this->clock))(new RestoreGameSystemRelease('bob', 'names', 1));
    }

    #[Test]
    public function anOwnerDiscardsAPackLikeADraft(): void
    {
        $this->createPack('names', 'Names', 'ann');

        (new DiscardGameSystemHandler($this->owned(), $this->gameSystems, $this->releases))(new DiscardGameSystem('ann', 'names'));

        self::assertSame([], $this->gameSystems->libraryPacks());
    }

    #[Test]
    public function theCatalogListsEverySectionWithLabelsKindsAndDirectDependenciesOnly(): void
    {
        $this->createPack('names', 'Names', 'ann', 'Generic tables');
        $pack = $this->gameSystems->ofKey('names');
        self::assertNotNull($pack);
        $now = $this->clock->now();
        $pack->saveNarrativeItem(NarrativeSection::Tags, null, ['key' => 'social', 'label' => 'Social'], $now);
        $pack->saveOracleTable(null, ['key' => 'people', 'name' => 'People', 'dice' => '1d6', 'entries' => [['min' => 1, 'max' => 6, 'text' => 'Aldric']]], $now);
        $pack->saveNarrativeItem(NarrativeSection::SceneTypes, null, [
            'key' => 'tavern', 'name' => 'Tavern', 'purpose' => 'Hear rumours.', 'oracles' => ['people'], 'setup' => [], 'play' => [],
            'closing' => [['key' => 'meet', 'kind' => 'choice', 'title' => 'Meet?', 'options' => [['key' => 'yes', 'label' => 'Yes', 'effects' => [['kind' => 'createNpc', 'name' => 'Innkeeper', 'tags' => ['social']]]], ['key' => 'no', 'label' => 'No']], 'skip' => 'no']],
        ], $now);
        $pack->saveOracleTable(null, ['key' => 'rumours', 'name' => 'Rumours', 'dice' => '1d6', 'entries' => [['min' => 1, 'max' => 6, 'text' => 'Drinks', 'sceneType' => 'tavern']]], $now);

        $catalog = $this->catalog('bob', 'names');

        self::assertSame(['names', 'Names', 'Generic tables', false], [$catalog->key, $catalog->name, $catalog->description, $catalog->mine]);
        self::assertTrue($this->catalog('ann', 'names')->mine);
        self::assertSame(array_column(LibrarySection::cases(), 'value'), array_map(static fn (\App\Studio\Application\LibraryPackCatalogSection $section): string => $section->section->value, $catalog->sections));
        $items = [];
        foreach ($catalog->sections as $section) {
            foreach ($section->items as $item) {
                $items[$section->section->value.'/'.$item->key] = [$item->label, $item->kind, array_map(static fn (\App\Studio\Domain\GameSystem\LibraryItemRef $ref): string => $ref->id(), $item->dependsOn)];
            }
        }

        self::assertSame([
            'oracles/people' => ['People', 'table', []],
            'oracles/rumours' => ['Rumours', 'table', ['scene-types/tavern']],
            'tags/social' => ['Social', null, []],
            'scene-types/tavern' => ['Tavern', null, ['oracles/people', 'tags/social']],
        ], $items);
    }

    #[Test]
    public function theCatalogOfAnUnknownKeyOrAGameSystemIsNotFound(): void
    {
        $this->createDraft('a-draft', 'ann');

        foreach (['nowhere', 'a-draft'] as $key) {
            try {
                $this->catalog('ann', $key);
                self::fail('A catalog was returned.');
            } catch (LibraryPackNotFound $notFound) {
                self::assertStringContainsString($key, $notFound->getMessage());
            }
        }
    }

    private function owned(): OwnedGameSystems
    {
        return new OwnedGameSystems($this->gameSystems);
    }

    private function createPack(string $key, string $name, string $owner, ?string $description = null): void
    {
        (new CreateLibraryPackHandler($this->gameSystems, $this->releases, $this->clock))(new CreateLibraryPack($this->ids->generate()->toString(), $owner, $key, $name, $description));
    }

    private function createDraft(string $key, string $owner): void
    {
        (new CreateGameSystemHandler($this->gameSystems, $this->releases, $this->clock))(new CreateGameSystem($this->ids->generate()->toString(), $owner, $key, ucfirst($key), null, null, null));
    }

    private function catalog(string $user, string $key): LibraryPackCatalog
    {
        return (new GetLibraryPackHandler(new LibraryPacks($this->gameSystems)))(new GetLibraryPack($user, $key));
    }

    /**
     * @return list<LibraryPackSummary>
     */
    private function library(string $user): array
    {
        return (new ListLibraryPacksHandler($this->gameSystems))(new ListLibraryPacks($user));
    }

    /**
     * @param array<mixed> $document
     */
    private function publishRelease(array $document): void
    {
        new ReleasePublisher($this->releases, $this->clock)->publish(ReleaseId::fromString('release-'.$this->releases->count()), ReleaseContent::fromArray($document), null, false);
    }
}
