<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Application;

use App\Studio\Application\CreateGameSystem;
use App\Studio\Application\CreateGameSystemHandler;
use App\Studio\Application\CreateLibraryPack;
use App\Studio\Application\CreateLibraryPackHandler;
use App\Studio\Application\DetachLibraryImport;
use App\Studio\Application\DetachLibraryImportHandler;
use App\Studio\Application\DiscardGameSystem;
use App\Studio\Application\DiscardGameSystemHandler;
use App\Studio\Application\GameSystemSummary;
use App\Studio\Application\GetGameSystem;
use App\Studio\Application\GetGameSystemHandler;
use App\Studio\Application\GetGameSystemLibraryImports;
use App\Studio\Application\GetGameSystemLibraryImportsHandler;
use App\Studio\Application\ImportLibraryItems;
use App\Studio\Application\ImportLibraryItemsHandler;
use App\Studio\Application\LibraryImportPreview;
use App\Studio\Application\LibraryImportStandings;
use App\Studio\Application\LibraryImportStatus;
use App\Studio\Application\LibraryImportView;
use App\Studio\Application\LibraryPackNotFound;
use App\Studio\Application\LibraryPacks;
use App\Studio\Application\ListMyGameSystems;
use App\Studio\Application\ListMyGameSystemsHandler;
use App\Studio\Application\OwnedGameSystems;
use App\Studio\Application\PreviewLibraryImport;
use App\Studio\Application\PreviewLibraryImportHandler;
use App\Studio\Application\PublishGameSystemDraft;
use App\Studio\Application\PublishGameSystemDraftHandler;
use App\Studio\Application\ReleasePublisher;
use App\Studio\Application\UpdateLibraryImport;
use App\Studio\Application\UpdateLibraryImportHandler;
use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\GameSystemModifiedConcurrently;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\GameSystem\LibraryImport;
use App\Studio\Domain\GameSystem\LibraryImportNotFound;
use App\Studio\Domain\GameSystem\LibraryItemKeyTaken;
use App\Studio\Domain\GameSystem\LibraryItemNotFound;
use App\Studio\Domain\GameSystem\LibraryItemRemoved;
use App\Studio\Domain\GameSystem\LibraryPackRefuses;
use App\Studio\Domain\GameSystem\LibrarySection;
use App\Studio\Domain\GameSystem\NarrativeSection;
use App\Studio\Domain\Release\GameSystemDraftUnchanged;
use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Tests\Support\Studio\FixedClock;
use App\Tests\Support\Studio\InMemoryGameSystemReleaseRepository;
use App\Tests\Support\Studio\InMemoryGameSystemRepository;
use App\Tests\Support\Studio\ReleaseArrays;
use App\Tests\Support\Studio\SequentialGameSystemIdGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Ann's pack "generic": tag social, table names and Scene Type tavern, which needs both. Bob's
 * draft "my-game" and Ann's draft "ann-game" start at version 1.
 */
#[CoversClass(ImportLibraryItemsHandler::class)]
#[CoversClass(PreviewLibraryImportHandler::class)]
#[CoversClass(UpdateLibraryImportHandler::class)]
#[CoversClass(DetachLibraryImportHandler::class)]
#[CoversClass(GetGameSystemLibraryImportsHandler::class)]
#[CoversClass(LibraryImportStandings::class)]
#[CoversClass(LibraryPacks::class)]
#[CoversClass(PublishGameSystemDraftHandler::class)]
final class LibraryImportHandlersTest extends TestCase
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

        $this->createPack('generic', 'Generic', 'ann');
        $pack = $this->get('generic');
        $pack->saveNarrativeItem(NarrativeSection::Tags, null, ['key' => 'social', 'label' => 'Social'], $this->clock->now());
        $pack->saveOracleTable(null, ['key' => 'names', 'name' => 'Names', 'dice' => '1d6', 'entries' => [['min' => 1, 'max' => 6, 'text' => 'Aldric']]], $this->clock->now());
        $pack->saveNarrativeItem(NarrativeSection::SceneTypes, null, [
            'key' => 'tavern', 'name' => 'Tavern', 'purpose' => 'Hear rumours.', 'oracles' => ['names'], 'setup' => [], 'play' => [],
            'closing' => [['key' => 'meet', 'kind' => 'choice', 'title' => 'Meet?', 'options' => [['key' => 'yes', 'label' => 'Yes', 'effects' => [['kind' => 'createNpc', 'name' => 'Innkeeper', 'tags' => ['social']]]], ['key' => 'no', 'label' => 'No']], 'skip' => 'no']],
        ], $this->clock->now());
        $this->createDraft('my-game', 'bob');
        $this->createDraft('ann-game', 'ann');
    }

    #[Test]
    public function importingCopiesTheItemsAndWhatTheyNeedAndSavesOnce(): void
    {
        $this->import('bob', 'my-game', 1, 'generic', [['section' => 'scene-types', 'key' => 'tavern']]);

        $draft = $this->get('my-game');
        self::assertSame(2, $draft->version());
        self::assertSame(['oracles/names', 'tags/social', 'scene-types/tavern'], array_map(static fn (LibraryImport $import): string => $import->ref()->id(), $draft->libraryImports()));
        self::assertSame(['names'], $this->keys($draft, 'oracles', 'tables'));
        self::assertSame(2, (new GetGameSystemLibraryImportsHandler($this->owned(), new LibraryImportStandings(new LibraryPacks($this->gameSystems))))(new GetGameSystemLibraryImports('bob', 'my-game'))->version);
    }

    #[Test]
    public function packsOfAnyOwnerAreImportable(): void
    {
        $this->import('ann', 'ann-game', 1, 'generic', [['section' => 'tags', 'key' => 'social']]);
        $this->import('bob', 'my-game', 1, 'generic', [['section' => 'tags', 'key' => 'social']]);

        self::assertCount(1, $this->get('my-game')->libraryImports());
        self::assertCount(1, $this->get('ann-game')->libraryImports());
    }

    #[Test]
    public function anUnknownPackAndAGameSystemAreNotPacks(): void
    {
        foreach (['nowhere', 'ann-game'] as $key) {
            try {
                $this->import('bob', 'my-game', 1, $key, [['section' => 'tags', 'key' => 'social']]);
                self::fail('The import went through.');
            } catch (LibraryPackNotFound $notFound) {
                self::assertStringContainsString($key, $notFound->getMessage());
            }

            try {
                $this->preview('bob', 'my-game', $key, [['section' => 'tags', 'key' => 'social']]);
                self::fail('The preview went through.');
            } catch (LibraryPackNotFound) {
            }
        }

        $this->assertDraftUntouched('my-game');
    }

    #[Test]
    public function anotherOwnersDraftBehavesAsMissingEverywhere(): void
    {
        $items = [['section' => 'tags', 'key' => 'social']];
        $this->import('bob', 'my-game', 1, 'generic', $items);

        foreach ([
            fn (): mixed => $this->import('ann', 'my-game', 2, 'generic', $items),
            fn (): mixed => $this->preview('ann', 'my-game', 'generic', $items),
            fn (): mixed => $this->update('ann', 'my-game', 2, 'tags', 'social'),
            fn (): mixed => $this->detach('ann', 'my-game', 2, 'tags', 'social'),
            fn (): mixed => $this->imports('ann', 'my-game'),
            fn (): mixed => $this->imports('bob', 'nowhere'),
        ] as $call) {
            try {
                $call();
                self::fail('The call went through.');
            } catch (GameSystemNotFound) {
            }
        }

        self::assertSame(2, $this->get('my-game')->version());
    }

    #[Test]
    public function aMissingItemATakenKeyAndAPackTargetAreRefusedAndChangeNothing(): void
    {
        $this->import('bob', 'my-game', 1, 'generic', [['section' => 'tags', 'key' => 'social']]);
        $before = $this->get('my-game')->document();

        foreach ([
            [LibraryItemNotFound::class, fn (): mixed => $this->import('bob', 'my-game', 2, 'generic', [['section' => 'trackers', 'key' => 'chaos']])],
            [LibraryItemKeyTaken::class, fn (): mixed => $this->import('bob', 'my-game', 2, 'generic', [['section' => 'tags', 'key' => 'social']])],
            [LibraryItemNotFound::class, fn (): mixed => $this->preview('bob', 'my-game', 'generic', [['section' => 'trackers', 'key' => 'chaos']])],
            [LibraryPackRefuses::class, fn (): mixed => $this->import('ann', 'generic', 1, 'generic', [['section' => 'tags', 'key' => 'social']])],
            [LibraryPackRefuses::class, fn (): mixed => $this->preview('ann', 'generic', 'generic', [['section' => 'tags', 'key' => 'social']])],
        ] as [$expected, $call]) {
            try {
                $call();
                self::fail(\sprintf('%s was not raised.', $expected));
            } catch (\Throwable $error) {
                self::assertInstanceOf($expected, $error);
            }
        }

        self::assertSame(2, $this->get('my-game')->version());
        self::assertSame($before, $this->get('my-game')->document());
    }

    #[Test]
    public function aStaleVersionIsRefusedForEveryWrite(): void
    {
        $this->import('bob', 'my-game', 1, 'generic', [['section' => 'tags', 'key' => 'social']]);

        foreach ([
            fn (): mixed => $this->import('bob', 'my-game', 1, 'generic', [['section' => 'oracles', 'key' => 'names']]),
            fn (): mixed => $this->update('bob', 'my-game', 1, 'tags', 'social'),
            fn (): mixed => $this->detach('bob', 'my-game', 1, 'tags', 'social'),
        ] as $call) {
            try {
                $call();
                self::fail('The stale write went through.');
            } catch (GameSystemModifiedConcurrently) {
            }
        }

        self::assertSame(2, $this->get('my-game')->version());
        self::assertCount(1, $this->get('my-game')->libraryImports());
    }

    #[Test]
    public function malformedItemsFailClosedNamingThePath(): void
    {
        foreach ([
            [null, 'items'],
            [[], 'items'],
            [['tags'], 'items[0].section'],
            [[['section' => 'flows', 'key' => 'a']], 'items[0].section'],
            [[['section' => 'tags']], 'items[0].key'],
            [[['section' => 'tags', 'key' => 5]], 'items[0].key'],
        ] as [$items, $path]) {
            foreach ([fn (): mixed => $this->import('bob', 'my-game', 1, 'generic', $items), fn (): mixed => $this->preview('bob', 'my-game', 'generic', $items)] as $call) {
                try {
                    $call();
                    self::fail('The malformed items were accepted.');
                } catch (InvalidReleaseContent $invalid) {
                    self::assertStringStartsWith($path, $invalid->getMessage());
                }
            }
        }

        $this->assertDraftUntouched('my-game');
    }

    #[Test]
    public function aPreviewShowsTheImportsOverridesAndConflictsAndWritesNothing(): void
    {
        $this->import('bob', 'my-game', 1, 'generic', [['section' => 'oracles', 'key' => 'names']]);
        $before = $this->get('my-game')->document();

        $preview = $this->preview('bob', 'my-game', 'generic', [['section' => 'scene-types', 'key' => 'tavern'], ['section' => 'oracles', 'key' => 'names']]);

        self::assertSame([['section' => 'tags', 'key' => 'social'], ['section' => 'scene-types', 'key' => 'tavern']], $preview->imports);
        self::assertSame([['section' => 'oracles', 'key' => 'names']], $preview->conflicts);
        self::assertSame([], $preview->overrides);
        self::assertNull($preview->error);
        self::assertSame(2, $this->get('my-game')->version());
        self::assertSame($before, $this->get('my-game')->document());

        $override = $this->preview('bob', 'my-game', 'generic', [['section' => 'scene-types', 'key' => 'tavern']]);
        self::assertSame([['section' => 'oracles', 'key' => 'names']], $override->overrides);
    }

    #[Test]
    public function aPreviewReportsTheItemRuleTheResultWouldBreak(): void
    {
        $source = ReleaseContent::fromArray(ReleaseArrays::fixture('valid/v2-every-part'))->toArray();
        $this->gameSystems->add(GameSystem::createFromRelease($this->ids->generate(), 'broken', 'Broken', null, 'bob', $source, $this->clock->now()));
        /** @var array<string, mixed> $broken */
        $broken = json_decode(str_replace('"tracker":"edge"', '"tracker":"missing"', json_encode($this->get('broken')->document(), \JSON_THROW_ON_ERROR)), true, flags: \JSON_THROW_ON_ERROR);
        new \ReflectionProperty(GameSystem::class, 'document')->setValue($this->get('broken'), $broken);

        $preview = $this->preview('bob', 'broken', 'generic', [['section' => 'tags', 'key' => 'social']]);

        self::assertSame([['section' => 'tags', 'key' => 'social']], $preview->imports);
        self::assertStringStartsWith('sceneTypes[0]', $preview->error['path'] ?? '');
        self::assertNotSame('', $preview->error['message'] ?? '');
    }

    #[Test]
    public function importsAreCurrentUntilThePackItemChangesOrGoesAndShowLocalEdits(): void
    {
        $this->import('bob', 'my-game', 1, 'generic', [['section' => 'scene-types', 'key' => 'tavern']]);

        self::assertSame(
            [['oracles/names', 'Names', 'current', false], ['tags/social', 'Social', 'current', false], ['scene-types/tavern', 'Tavern', 'current', false]],
            $this->summary($this->imports('bob', 'my-game')),
        );
        self::assertSame(['key' => 'generic', 'name' => 'Generic'], $this->imports('bob', 'my-game')[0]->pack);

        $pack = $this->get('generic');
        $pack->saveNarrativeItem(NarrativeSection::Tags, 'social', ['key' => 'social', 'label' => 'Social life'], $this->clock->now());
        $pack->deleteNarrativeItem(NarrativeSection::SceneTypes, 'tavern', $this->clock->now());
        $draft = $this->get('my-game');
        $draft->saveOracleTable('names', ['key' => 'names', 'name' => 'My names', 'dice' => '1d6', 'entries' => [['min' => 1, 'max' => 6, 'text' => 'Bram']]], $this->clock->now());

        self::assertSame(
            [['oracles/names', 'My names', 'current', true], ['tags/social', 'Social', 'changed', false], ['scene-types/tavern', 'Tavern', 'removed', false]],
            $this->summary($this->imports('bob', 'my-game')),
        );
    }

    #[Test]
    public function aDeletedPackLeavesItsImportsRemovedWithNoPack(): void
    {
        $this->import('bob', 'my-game', 1, 'generic', [['section' => 'tags', 'key' => 'social']]);
        (new DiscardGameSystemHandler($this->owned(), $this->gameSystems, $this->releases))(new DiscardGameSystem('ann', 'generic'));

        $views = $this->imports('bob', 'my-game');

        self::assertSame([LibraryImportStatus::Removed], array_map(static fn (LibraryImportView $view): LibraryImportStatus => $view->status, $views));
        self::assertNull($views[0]->pack);
        self::assertSame('Social', $views[0]->label);
    }

    #[Test]
    public function aDraftWithNoImportsListsNone(): void
    {
        self::assertSame([], $this->imports('bob', 'my-game'));
        self::assertSame([], $this->imports('ann', 'generic'));
    }

    #[Test]
    public function updatingReplacesTheItemWithThePacksCurrentOneAndSaves(): void
    {
        $this->import('bob', 'my-game', 1, 'generic', [['section' => 'tags', 'key' => 'social']]);
        $this->get('generic')->saveNarrativeItem(NarrativeSection::Tags, 'social', ['key' => 'social', 'label' => 'Social life'], $this->clock->now());

        $this->update('bob', 'my-game', 2, 'tags', 'social');

        self::assertSame(3, $this->get('my-game')->version());
        self::assertSame([['tags/social', 'Social life', 'current', false]], $this->summary($this->imports('bob', 'my-game')));
    }

    #[Test]
    public function updatingAnItemWhosePackOrItemIsGoneIsRemovedAndChangesNothing(): void
    {
        $this->import('bob', 'my-game', 1, 'generic', [['section' => 'scene-types', 'key' => 'tavern']]);
        $this->get('generic')->deleteNarrativeItem(NarrativeSection::SceneTypes, 'tavern', $this->clock->now());
        $before = $this->get('my-game')->document();

        try {
            $this->update('bob', 'my-game', 2, 'scene-types', 'tavern');
            self::fail('The update went through.');
        } catch (LibraryItemRemoved $removed) {
            self::assertStringContainsString('"generic"', $removed->getMessage());
        }

        (new DiscardGameSystemHandler($this->owned(), $this->gameSystems, $this->releases))(new DiscardGameSystem('ann', 'generic'));

        $this->expectException(LibraryItemRemoved::class);
        try {
            $this->update('bob', 'my-game', 2, 'oracles', 'names');
        } finally {
            self::assertSame($before, $this->get('my-game')->document());
            self::assertSame(2, $this->get('my-game')->version());
        }
    }

    #[Test]
    public function updatingOrDetachingAnItemThatWasNotImportedIsNotFound(): void
    {
        foreach ([fn (): mixed => $this->update('bob', 'my-game', 1, 'tags', 'social'), fn (): mixed => $this->detach('bob', 'my-game', 1, 'tags', 'social')] as $call) {
            try {
                $call();
                self::fail('The call went through.');
            } catch (LibraryImportNotFound) {
            }
        }

        $this->assertDraftUntouched('my-game');
    }

    #[Test]
    public function aPackNeverImportsSoDetachRefusesItAndUpdateFindsNoImport(): void
    {
        try {
            $this->detach('ann', 'generic', 1, 'tags', 'social');
            self::fail('The detach went through.');
        } catch (LibraryPackRefuses) {
        }

        $this->expectException(LibraryImportNotFound::class);

        $this->update('ann', 'generic', 1, 'tags', 'social');
    }

    #[Test]
    public function detachingKeepsTheItemAndForgetsTheImport(): void
    {
        $this->import('bob', 'my-game', 1, 'generic', [['section' => 'tags', 'key' => 'social']]);

        $this->detach('bob', 'my-game', 2, 'tags', 'social');

        $draft = $this->get('my-game');
        self::assertSame(3, $draft->version());
        self::assertSame([], $this->imports('bob', 'my-game'));
        self::assertSame(['social'], $this->keys($draft, 'tags'));
    }

    #[Test]
    public function publishingRecordsTheImportsBesideTheReleaseOutsideItsHash(): void
    {
        $this->import('bob', 'my-game', 1, 'generic', [['section' => 'tags', 'key' => 'social']]);

        $this->publish('bob', 'my-game');

        $release = $this->releases->latestFor('my-game');
        self::assertNotNull($release);
        self::assertSame([['section' => 'tags', 'key' => 'social', 'pack' => 'generic', 'hash' => ReleaseContent::itemHash(['key' => 'social', 'label' => 'Social'])]], $release->librarySources());
        self::assertSame(ReleaseContent::fromArray($this->get('my-game')->document())->hash(), $release->contentHash());
        self::assertSame(ReleaseContent::fromArray($release->content()->toArray())->hash(), $release->contentHash());
    }

    #[Test]
    public function aDraftWithoutImportsPublishesWithNullLibrarySourcesAndTheSameHash(): void
    {
        $this->createDraft('plain', 'bob');
        $this->import('bob', 'my-game', 1, 'generic', [['section' => 'tags', 'key' => 'social']]);
        $this->detach('bob', 'my-game', 2, 'tags', 'social');
        $this->publish('bob', 'plain');
        $this->publish('bob', 'my-game');

        $plain = $this->releases->latestFor('plain');
        $detached = $this->releases->latestFor('my-game');

        self::assertNotNull($plain);
        self::assertNull($plain->librarySources());
        self::assertNull($detached?->librarySources());
    }

    #[Test]
    public function aMissingPackIsLookedUpOnceForAllItsImports(): void
    {
        $this->import('bob', 'my-game', 1, 'generic', [['section' => 'scene-types', 'key' => 'tavern']]);
        $this->gameSystems->remove($this->get('generic'));
        $counting = new LookupCountingGameSystemRepository($this->gameSystems, 'generic');

        $views = (new GetGameSystemLibraryImportsHandler($this->owned(), new LibraryImportStandings(new LibraryPacks($counting))))(new GetGameSystemLibraryImports('bob', 'my-game'))->imports;

        self::assertCount(3, $views);
        self::assertSame([LibraryImportStatus::Removed], array_values(array_unique(array_map(static fn (LibraryImportView $view): LibraryImportStatus => $view->status, $views), \SORT_REGULAR)));
        self::assertSame(1, $counting->lookups);
    }

    #[Test]
    public function aDraftCountsItsChangedAndRemovedImportsAsLibraryChanges(): void
    {
        $this->import('bob', 'my-game', 1, 'generic', [['section' => 'scene-types', 'key' => 'tavern']]);
        self::assertSame(0, $this->libraryChanges('bob', 'my-game'));

        $pack = $this->get('generic');
        $pack->saveNarrativeItem(NarrativeSection::Tags, 'social', ['key' => 'social', 'label' => 'Social v2'], $this->clock->now());
        self::assertSame(1, $this->libraryChanges('bob', 'my-game'));

        $pack->deleteNarrativeItem(NarrativeSection::SceneTypes, 'tavern', $this->clock->now());
        self::assertSame(2, $this->libraryChanges('bob', 'my-game'));

        $this->gameSystems->remove($pack);
        self::assertSame(3, $this->libraryChanges('bob', 'my-game'));
    }

    #[Test]
    public function aLocalEditAloneIsNotALibraryChange(): void
    {
        $this->import('bob', 'my-game', 1, 'generic', [['section' => 'tags', 'key' => 'social']]);
        $this->get('my-game')->saveNarrativeItem(NarrativeSection::Tags, 'social', ['key' => 'social', 'label' => 'Mine'], $this->clock->now());

        self::assertTrue($this->imports('bob', 'my-game')[0]->editedLocally);
        self::assertSame(0, $this->libraryChanges('bob', 'my-game'));
    }

    #[Test]
    public function aDraftWithoutImportsAndALibraryPackHaveNoLibraryChangesAndLoadNoPack(): void
    {
        $counting = new LookupCountingGameSystemRepository($this->gameSystems, 'generic');
        $standings = new LibraryImportStandings(new LibraryPacks($counting));

        self::assertSame(0, $standings->changesOf($this->get('my-game')));
        self::assertSame(0, $standings->changesOf($this->get('generic')));
        self::assertSame(0, $counting->lookups);
        self::assertSame(0, $this->libraryChanges('ann', 'ann-game'));
    }

    #[Test]
    public function theDraftListAndDetailAgreeAndLookEachPackUpOnce(): void
    {
        $this->import('bob', 'my-game', 1, 'generic', [['section' => 'tags', 'key' => 'social']]);
        $this->createDraft('second', 'bob');
        $this->import('bob', 'second', 1, 'generic', [['section' => 'tags', 'key' => 'social']]);
        $this->createDraft('plain', 'bob');
        $this->get('generic')->saveNarrativeItem(NarrativeSection::Tags, 'social', ['key' => 'social', 'label' => 'Social v2'], $this->clock->now());
        $counting = new LookupCountingGameSystemRepository($this->gameSystems, 'generic');

        $list = (new ListMyGameSystemsHandler($counting, $this->releases, new LibraryImportStandings(new LibraryPacks($counting))))(new ListMyGameSystems('bob'));

        self::assertSame(1, $counting->lookups);
        self::assertEqualsCanonicalizing(['my-game' => 1, 'second' => 1, 'plain' => 0], array_column(array_map(static fn (GameSystemSummary $summary): array => [$summary->key, $summary->libraryChanges], $list), 1, 0));
        self::assertSame(1, $this->libraryChanges('bob', 'my-game'));
    }

    #[Test]
    public function detachingAfterPublishingChangesNothingAboutTheEarlierRelease(): void
    {
        $this->import('bob', 'my-game', 1, 'generic', [['section' => 'tags', 'key' => 'social']]);
        $this->publish('bob', 'my-game');
        $release = $this->releases->latestFor('my-game');
        self::assertNotNull($release);
        $sources = $release->librarySources();
        self::assertNotNull($sources);

        $this->detach('bob', 'my-game', 2, 'tags', 'social');

        try {
            $this->publish('bob', 'my-game');
            self::fail('The unchanged draft was published again.');
        } catch (GameSystemDraftUnchanged) {
        }

        self::assertSame(1, $this->releases->count());
        self::assertSame($sources, $this->releases->latestFor('my-game')?->librarySources());
    }

    private function owned(): OwnedGameSystems
    {
        return new OwnedGameSystems($this->gameSystems);
    }

    private function get(string $key): GameSystem
    {
        $gameSystem = $this->gameSystems->ofKey($key);
        self::assertNotNull($gameSystem);

        return $gameSystem;
    }

    private function createPack(string $key, string $name, string $owner): void
    {
        (new CreateLibraryPackHandler($this->gameSystems, $this->releases, $this->clock))(new CreateLibraryPack($this->ids->generate()->toString(), $owner, $key, $name, null));
    }

    private function createDraft(string $key, string $owner): void
    {
        (new CreateGameSystemHandler($this->gameSystems, $this->releases, $this->clock))(new CreateGameSystem($this->ids->generate()->toString(), $owner, $key, ucfirst($key), null, null, null));
    }

    private function import(string $owner, string $key, int $version, string $pack, mixed $items): GameSystem
    {
        (new ImportLibraryItemsHandler($this->owned(), new LibraryPacks($this->gameSystems), $this->gameSystems, $this->clock))(new ImportLibraryItems($owner, $key, $version, $pack, $items));

        return $this->get($key);
    }

    private function preview(string $owner, string $key, string $pack, mixed $items): LibraryImportPreview
    {
        return (new PreviewLibraryImportHandler($this->owned(), new LibraryPacks($this->gameSystems)))(new PreviewLibraryImport($owner, $key, $pack, $items));
    }

    private function update(string $owner, string $key, int $version, string $section, string $item): GameSystem
    {
        (new UpdateLibraryImportHandler($this->owned(), new LibraryPacks($this->gameSystems), $this->gameSystems, $this->clock))(new UpdateLibraryImport($owner, $key, $version, LibrarySection::from($section), $item));

        return $this->get($key);
    }

    private function detach(string $owner, string $key, int $version, string $section, string $item): GameSystem
    {
        (new DetachLibraryImportHandler($this->owned(), $this->gameSystems, $this->clock))(new DetachLibraryImport($owner, $key, $version, LibrarySection::from($section), $item));

        return $this->get($key);
    }

    /**
     * @return list<LibraryImportView>
     */
    private function imports(string $owner, string $key): array
    {
        return (new GetGameSystemLibraryImportsHandler($this->owned(), new LibraryImportStandings(new LibraryPacks($this->gameSystems))))(new GetGameSystemLibraryImports($owner, $key))->imports;
    }

    private function libraryChanges(string $owner, string $key): int
    {
        return (new GetGameSystemHandler($this->owned(), $this->releases, new LibraryImportStandings(new LibraryPacks($this->gameSystems))))(new GetGameSystem($owner, $key))->libraryChanges;
    }

    private function publish(string $owner, string $key): void
    {
        (new PublishGameSystemDraftHandler($this->owned(), new ReleasePublisher($this->releases, $this->clock), $this->releases))(new PublishGameSystemDraft($owner, $key, 'release-'.$this->releases->count(), null));
    }

    /**
     * @param list<LibraryImportView> $views
     *
     * @return list<array{string, string, string, bool}>
     */
    private function summary(array $views): array
    {
        return array_map(static fn (LibraryImportView $view): array => [$view->section->value.'/'.$view->key, $view->label, $view->status->value, $view->editedLocally], $views);
    }

    /**
     * @return list<string> the keys of a list of the draft's document
     */
    private function keys(GameSystem $draft, string ...$path): array
    {
        $node = $draft->document();
        foreach ($path as $part) {
            $node = \is_array($node) ? $node[$part] ?? [] : [];
        }

        $keys = [];
        foreach (\is_array($node) ? $node : [] as $item) {
            \assert(\is_array($item) && \is_string($item['key']));
            $keys[] = $item['key'];
        }

        return $keys;
    }

    private function assertDraftUntouched(string $key): void
    {
        self::assertSame(1, $this->get($key)->version());
        self::assertSame([], $this->get($key)->libraryImports());
    }
}
