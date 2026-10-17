<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\GameSystem;

use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\GameSystemId;
use App\Studio\Domain\GameSystem\GameSystemMetadata;
use App\Studio\Domain\GameSystem\LibraryImport;
use App\Studio\Domain\GameSystem\LibraryImportNotFound;
use App\Studio\Domain\GameSystem\LibraryImportPlan;
use App\Studio\Domain\GameSystem\LibraryItemKeyTaken;
use App\Studio\Domain\GameSystem\LibraryItemNotFound;
use App\Studio\Domain\GameSystem\LibraryItemRef;
use App\Studio\Domain\GameSystem\LibraryItemRemoved;
use App\Studio\Domain\GameSystem\LibraryItems;
use App\Studio\Domain\GameSystem\LibraryPackRefuses;
use App\Studio\Domain\GameSystem\LibrarySection;
use App\Studio\Domain\GameSystem\NarrativeSection;
use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The pack: trackers chaos and alarm, tag social, fact slot secret, table names, table rumours
 * (an entry opens the Scene Type), likelihood oracle fate (chaos tracker chaos) and Scene Type
 * tavern, which needs table names, tracker alarm, tag social and fact slot secret.
 */
#[CoversClass(GameSystem::class)]
#[CoversClass(LibraryItems::class)]
#[CoversClass(LibraryItemRef::class)]
#[CoversClass(LibraryImport::class)]
#[CoversClass(LibraryImportPlan::class)]
#[CoversClass(LibraryItemNotFound::class)]
#[CoversClass(LibraryItemKeyTaken::class)]
#[CoversClass(LibraryImportNotFound::class)]
#[CoversClass(LibraryItemRemoved::class)]
final class LibraryImportTest extends TestCase
{
    private \DateTimeImmutable $created;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->created = new \DateTimeImmutable('2026-10-18T10:00:00');
        $this->now = new \DateTimeImmutable('2026-10-18T12:00:00');
    }

    private function pack(): GameSystem
    {
        $pack = GameSystem::createLibraryPack(GameSystemId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a61'), 'generic', 'user-1', 'Generic', null, $this->created);
        $pack->saveNarrativeItem(NarrativeSection::Trackers, null, ['key' => 'chaos', 'name' => 'Chaos', 'kind' => 'counter', 'min' => 1, 'max' => 9, 'initial' => 5], $this->now);
        $pack->saveNarrativeItem(NarrativeSection::Trackers, null, ['key' => 'alarm', 'name' => 'Alarm', 'kind' => 'clock', 'segments' => 6], $this->now);
        $pack->saveNarrativeItem(NarrativeSection::Tags, null, ['key' => 'social', 'label' => 'Social'], $this->now);
        $pack->saveNarrativeItem(NarrativeSection::FactSlots, null, ['key' => 'secret', 'label' => 'Secret', 'type' => 'text'], $this->now);
        $pack->saveOracleTable(null, $this->table('names'), $this->now);
        $pack->saveLikelihoodOracle(null, ['key' => 'fate', 'name' => 'Fate', 'sides' => 100, 'levels' => [['key' => 'even', 'label' => '50/50', 'target' => 50]], 'chaos' => ['min' => 1, 'max' => 9, 'neutral' => 5, 'shiftPerPoint' => 5, 'tracker' => 'chaos'], 'exceptionalPercent' => 20], $this->now);
        $pack->saveNarrativeItem(NarrativeSection::SceneTypes, null, [
            'key' => 'tavern', 'name' => 'Tavern', 'purpose' => 'Hear rumours.', 'oracles' => ['names'], 'setup' => [], 'play' => [],
            'closing' => [['key' => 'trouble', 'kind' => 'choice', 'title' => 'Trouble?', 'options' => [
                ['key' => 'yes', 'label' => 'Yes', 'effects' => [
                    ['kind' => 'tracker', 'tracker' => 'alarm', 'op' => 'add', 'value' => 1],
                    ['kind' => 'createNpc', 'name' => 'Innkeeper', 'tags' => ['social']],
                    ['kind' => 'fillFact', 'slot' => 'secret'],
                ]],
                ['key' => 'no', 'label' => 'No'],
            ], 'skip' => 'no']],
        ], $this->now);
        $pack->saveOracleTable(null, [...$this->table('rumours'), 'entries' => [['min' => 1, 'max' => 6, 'text' => 'Drinks', 'sceneType' => 'tavern']]], $this->now);

        return $pack;
    }

    /**
     * @return array<string, mixed>
     */
    private function table(string $key): array
    {
        return ['key' => $key, 'name' => ucfirst($key), 'dice' => '1d6', 'entries' => [['min' => 1, 'max' => 6, 'text' => 'Aldric']]];
    }

    private function draft(): GameSystem
    {
        return GameSystem::createBlank(GameSystemId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a62'), 'my-game', 'My game', null, 'user-2', $this->created);
    }

    private function ref(LibrarySection $section, string $key): LibraryItemRef
    {
        return new LibraryItemRef($section, $key);
    }

    /**
     * @param list<LibraryItemRef> $refs
     *
     * @return list<string>
     */
    private function ids(array $refs): array
    {
        return array_map(static fn (LibraryItemRef $ref): string => $ref->id(), $refs);
    }

    /**
     * @param string $list a dotted path of the document, e.g. "oracles.tables" or "tags"
     *
     * @return list<array<string, mixed>>
     */
    private function items(GameSystem $gameSystem, string $list): array
    {
        $node = $gameSystem->document();
        foreach (explode('.', $list) as $part) {
            \assert(\is_array($node));
            $node = $node[$part];
        }

        /** @var list<array<string, mixed>> $items */
        $items = $node;

        return $items;
    }

    /**
     * @return list<string> the keys of the items of a list, in order
     */
    private function keys(GameSystem $gameSystem, string $list): array
    {
        /** @var list<string> $keys */
        $keys = array_column($this->items($gameSystem, $list), 'key');

        return $keys;
    }

    /**
     * @return list<string> the imports as section/key
     */
    private function imported(GameSystem $gameSystem): array
    {
        return array_map(static fn (LibraryImport $import): string => $import->ref()->id(), $gameSystem->libraryImports());
    }

    /**
     * @param callable(): mixed        $action
     * @param class-string<\Throwable> $exception
     */
    private function assertUntouched(GameSystem $gameSystem, callable $action, string $exception): void
    {
        $document = $gameSystem->document();
        $imports = $gameSystem->libraryImports();
        try {
            $action();
            self::fail('The command was accepted.');
        } catch (\Throwable $refusal) {
            self::assertInstanceOf($exception, $refusal);
        }

        self::assertSame($document, $gameSystem->document());
        self::assertEquals($imports, $gameSystem->libraryImports());
        self::assertEquals($this->created, $gameSystem->updatedAt());
    }

    #[Test]
    public function anItemHashDoesNotDependOnKeyOrderOrNumberSpelling(): void
    {
        $item = ['key' => 'chaos', 'name' => 'Chaos', 'levels' => [['upTo' => 1, 'label' => 'Low'], ['label' => 'High']], 'min' => 1, 'max' => 9];
        $reordered = ['max' => 9.0, 'min' => 1, 'levels' => [['label' => 'Low', 'upTo' => 1.0], ['label' => 'High']], 'name' => 'Chaos', 'key' => 'chaos'];

        self::assertSame(ReleaseContent::itemHash($item), ReleaseContent::itemHash($reordered));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', ReleaseContent::itemHash($item));
        self::assertNotSame(ReleaseContent::itemHash($item), ReleaseContent::itemHash([...$item, 'max' => 10]));
        // A list is ordered: its order is content.
        self::assertNotSame(ReleaseContent::itemHash($item), ReleaseContent::itemHash([...$item, 'levels' => array_reverse($item['levels'])]));
    }

    #[Test]
    public function aSceneTypeComesWithItsClosureInPackOrderAndEachCopyRemembersItsHash(): void
    {
        $pack = $this->pack();
        $draft = $this->draft();

        $draft->importFromLibrary($pack, [$this->ref(LibrarySection::SceneTypes, 'tavern')], $this->now);

        self::assertSame(['names'], $this->keys($draft, 'oracles.tables'));
        self::assertSame(['alarm'], $this->keys($draft, 'trackers'));
        self::assertSame(['social'], $this->keys($draft, 'tags'));
        self::assertSame(['secret'], $this->keys($draft, 'factSlots'));
        self::assertSame(['tavern'], $this->keys($draft, 'sceneTypes'));
        self::assertSame(['oracles/names', 'trackers/alarm', 'tags/social', 'fact-slots/secret', 'scene-types/tavern'], $this->imported($draft));
        self::assertEquals([new LibraryImport(LibrarySection::Tags, 'social', 'generic', ReleaseContent::itemHash(['key' => 'social', 'label' => 'Social']))], array_values(array_filter($draft->libraryImports(), static fn (LibraryImport $import): bool => LibrarySection::Tags === $import->section)));
        self::assertEquals($this->now, $draft->updatedAt());
        ReleaseContent::checkItemRules($draft->document());
    }

    #[Test]
    public function theClosureFollowsReferencesTransitivelyAndAnItemNamedTwiceIsCopiedOnce(): void
    {
        $draft = $this->draft();

        // rumours -> tavern -> names, alarm, social, secret
        $draft->importFromLibrary($this->pack(), [$this->ref(LibrarySection::Oracles, 'rumours'), $this->ref(LibrarySection::Oracles, 'names')], $this->now);

        self::assertSame(['oracles/names', 'oracles/rumours', 'trackers/alarm', 'tags/social', 'fact-slots/secret', 'scene-types/tavern'], $this->imported($draft));
        self::assertSame(['names', 'rumours'], $this->keys($draft, 'oracles.tables'));
    }

    #[Test]
    public function aLikelihoodOracleBringsItsChaosTracker(): void
    {
        $draft = $this->draft();

        $draft->importFromLibrary($this->pack(), [$this->ref(LibrarySection::Oracles, 'fate')], $this->now);

        self::assertSame(['fate'], $this->keys($draft, 'oracles.likelihood'));
        self::assertSame(['chaos'], $this->keys($draft, 'trackers'));
        self::assertSame(['oracles/fate', 'trackers/chaos'], $this->imported($draft));
    }

    #[Test]
    public function aNeededItemTheDraftHasIsAnOverrideAcrossSectionsAndStopsTheClosure(): void
    {
        $draft = $this->draft();
        $draft->saveNarrativeItem(NarrativeSection::Trackers, null, ['key' => 'alarm', 'name' => 'My alarm', 'kind' => 'counter', 'min' => 0, 'max' => 3, 'initial' => 0], $this->created);
        // The shared oracle namespace: a likelihood oracle named "names" answers for the pack's table "names".
        $draft->saveLikelihoodOracle(null, ['key' => 'names', 'name' => 'Names', 'sides' => 100, 'levels' => [['key' => 'even', 'label' => '50/50', 'target' => 50]]], $this->created);
        $draft->saveNarrativeItem(NarrativeSection::Tags, null, ['key' => 'social', 'label' => 'Mine'], $this->created);

        $plan = $draft->planLibraryImport($this->pack(), [$this->ref(LibrarySection::SceneTypes, 'tavern')]);

        self::assertSame(['fact-slots/secret', 'scene-types/tavern'], $this->ids($plan->imports));
        self::assertSame(['oracles/names', 'trackers/alarm', 'tags/social'], $this->ids($plan->overrides));
        self::assertSame([], $plan->conflicts);
        self::assertNull($plan->errorPath);

        $draft->importFromLibrary($this->pack(), [$this->ref(LibrarySection::SceneTypes, 'tavern')], $this->now);

        self::assertSame(['fact-slots/secret', 'scene-types/tavern'], $this->imported($draft));
        self::assertSame(['My alarm'], array_column($this->items($draft, 'trackers'), 'name'));
        self::assertSame([], $this->items($draft, 'oracles.tables'));
    }

    #[Test]
    public function aRequestedItemTheDraftHasIsAConflictAndEveryClashIsListed(): void
    {
        $pack = $this->pack();
        $draft = $this->draft();
        $draft->saveLikelihoodOracle(null, ['key' => 'names', 'name' => 'Names', 'sides' => 100, 'levels' => [['key' => 'even', 'label' => '50/50', 'target' => 50]]], $this->created);
        $draft->saveNarrativeItem(NarrativeSection::Tags, null, ['key' => 'social', 'label' => 'Mine'], $this->created);
        $requested = [$this->ref(LibrarySection::Oracles, 'names'), $this->ref(LibrarySection::Trackers, 'chaos'), $this->ref(LibrarySection::Tags, 'social')];

        $plan = $draft->planLibraryImport($pack, $requested);

        self::assertSame(['oracles/names', 'tags/social'], $this->ids($plan->conflicts));
        self::assertSame(['trackers/chaos'], $this->ids($plan->imports));
        $this->assertUntouched($draft, fn () => $draft->importFromLibrary($pack, $requested, $this->now), LibraryItemKeyTaken::class);
        try {
            $draft->importFromLibrary($pack, $requested, $this->now);
        } catch (LibraryItemKeyTaken $taken) {
            self::assertStringContainsString('oracle "names"', $taken->getMessage());
            self::assertStringContainsString('tag "social"', $taken->getMessage());
            self::assertStringNotContainsString('chaos', $taken->getMessage());
        }
    }

    #[Test]
    public function anAlreadyImportedItemIsAConflict(): void
    {
        $pack = $this->pack();
        $draft = $this->draft();
        $draft->importFromLibrary($pack, [$this->ref(LibrarySection::Trackers, 'chaos')], $this->created);

        $this->assertUntouched($draft, fn () => $draft->importFromLibrary($pack, [$this->ref(LibrarySection::Trackers, 'chaos')], $this->now), LibraryItemKeyTaken::class);
    }

    #[Test]
    public function anUnknownPackItemIsRefusedAndNothingChanges(): void
    {
        $pack = $this->pack();
        $draft = $this->draft();

        $this->assertUntouched($draft, fn () => $draft->importFromLibrary($pack, [$this->ref(LibrarySection::Trackers, 'chaos'), $this->ref(LibrarySection::Tags, 'chaos')], $this->now), LibraryItemNotFound::class);
        $this->expectExceptionMessageIsOrContains('tag "chaos"');

        $draft->planLibraryImport($pack, [$this->ref(LibrarySection::Tags, 'chaos')]);
    }

    #[Test]
    public function aFailingItemRuleLeavesDocumentAndImportsUntouchedAndThePlanReportsIt(): void
    {
        $pack = $this->pack();
        // A draft may be invalid while in progress: this one's Scene Type names a missing tracker.
        $source = ReleaseContent::fromArray(ReleaseArrays::fixture('valid/v2-every-part'))->toArray();
        $draft = GameSystem::createFromRelease(GameSystemId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a62'), 'my-game', 'My game', null, 'user-2', $source, $this->created);
        /** @var array<string, mixed> $broken */
        $broken = json_decode(str_replace('"tracker":"edge"', '"tracker":"missing"', json_encode($draft->document(), \JSON_THROW_ON_ERROR)), true, flags: \JSON_THROW_ON_ERROR);
        new \ReflectionProperty(GameSystem::class, 'document')->setValue($draft, $broken);

        $plan = $draft->planLibraryImport($pack, [$this->ref(LibrarySection::Tags, 'social')]);

        self::assertSame(['tags/social'], $this->ids($plan->imports));
        self::assertStringStartsWith('sceneTypes[0].closing[0].options[0].effects[0]', (string) $plan->errorPath);
        self::assertNotSame('', (string) $plan->errorMessage);
        $this->assertUntouched($draft, fn () => $draft->importFromLibrary($pack, [$this->ref(LibrarySection::Tags, 'social')], $this->now), InvalidReleaseContent::class);
    }

    #[Test]
    public function aPackTargetRefusesEveryImportOperation(): void
    {
        $pack = $this->pack();
        $other = GameSystem::createLibraryPack(GameSystemId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a63'), 'other', 'user-1', 'Other', null, $this->created);
        $items = [$this->ref(LibrarySection::Trackers, 'chaos')];

        foreach ([
            static fn (): LibraryImportPlan => $other->planLibraryImport($pack, $items),
            fn () => $other->importFromLibrary($pack, $items, $this->now),
            fn () => $other->updateFromLibrary($pack, LibrarySection::Trackers, 'chaos', $this->now),
            fn () => $other->detachLibraryImport(LibrarySection::Trackers, 'chaos', $this->now),
        ] as $operation) {
            $this->assertUntouched($other, $operation, LibraryPackRefuses::class);
        }
    }

    #[Test]
    public function aSchema1DraftIsRefusedLikeTheSheetCommandsDo(): void
    {
        $source = ReleaseContent::fromArray(ReleaseArrays::fixture('valid/minimal'))->toArray();
        $draft = GameSystem::createFromRelease(GameSystemId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a62'), 'old', 'Old', null, 'user-2', $source, $this->created);
        $pack = $this->pack();
        $items = [$this->ref(LibrarySection::Trackers, 'chaos')];

        foreach ([
            static fn (): LibraryImportPlan => $draft->planLibraryImport($pack, $items),
            fn () => $draft->importFromLibrary($pack, $items, $this->now),
            fn () => $draft->updateFromLibrary($pack, LibrarySection::Trackers, 'chaos', $this->now),
        ] as $operation) {
            $this->assertUntouched($draft, $operation, InvalidReleaseContent::class);
        }

        $this->expectExceptionMessageIsOrContains('schemaVersion: library imports need schema version 2');

        $draft->importFromLibrary($pack, $items, $this->now);
    }

    #[Test]
    public function onlyAPackCanBeImportedFromAndAnEmptyRequestIsRefused(): void
    {
        $draft = $this->draft();
        $notAPack = GameSystem::createBlank(GameSystemId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a64'), 'plain', 'Plain', null, 'user-1', $this->created);
        $notAPack->saveNarrativeItem(NarrativeSection::Tags, null, ['key' => 'social', 'label' => 'Social'], $this->created);

        $this->assertUntouched($draft, fn () => $draft->importFromLibrary($notAPack, [$this->ref(LibrarySection::Tags, 'social')], $this->now), \InvalidArgumentException::class);
        $this->assertUntouched($draft, fn () => $draft->updateFromLibrary($notAPack, LibrarySection::Tags, 'social', $this->now), \InvalidArgumentException::class);
        $this->assertUntouched($draft, fn () => $draft->importFromLibrary($this->pack(), [], $this->now), InvalidReleaseContent::class);
    }

    #[Test]
    public function anUpdateReplacesTheItemInPlaceAndImportsWhatItNowNeeds(): void
    {
        $pack = $this->pack();
        $draft = $this->draft();
        $draft->importFromLibrary($pack, [$this->ref(LibrarySection::Tags, 'social')], $this->created);
        $draft->saveNarrativeItem(NarrativeSection::Tags, null, ['key' => 'plain', 'label' => 'Plain'], $this->created);
        $draft->saveNarrativeItem(NarrativeSection::Tags, 'social', ['key' => 'social', 'label' => 'Edited here'], $this->created);
        $before = $draft->libraryImports();
        // The pack's tag is edited, and a Scene Type now names it.
        $pack->saveNarrativeItem(NarrativeSection::Tags, 'social', ['key' => 'social', 'label' => 'Social v2'], $this->now);

        $draft->updateFromLibrary($pack, LibrarySection::Tags, 'social', $this->now);

        self::assertSame(['social', 'plain'], $this->keys($draft, 'tags'));
        self::assertSame(['Social v2', 'Plain'], array_column($this->items($draft, 'tags'), 'label'));
        self::assertSame(ReleaseContent::itemHash(['key' => 'social', 'label' => 'Social v2']), $draft->libraryImports()[0]->hash);
        self::assertNotSame($before[0]->hash, $draft->libraryImports()[0]->hash);
        self::assertEquals($this->now, $draft->updatedAt());
    }

    #[Test]
    public function anUpdateImportsNewlyNeededItemsWithTheOverrideRule(): void
    {
        $pack = $this->pack();
        $draft = $this->draft();
        $draft->importFromLibrary($pack, [$this->ref(LibrarySection::Oracles, 'names')], $this->created);
        $draft->saveNarrativeItem(NarrativeSection::Trackers, null, ['key' => 'alarm', 'name' => 'My alarm', 'kind' => 'counter', 'min' => 0, 'max' => 3, 'initial' => 0], $this->created);
        // The pack's table "names" now opens the Scene Type, which needs alarm (an override), social and secret.
        $pack->saveOracleTable('names', [...$this->table('names'), 'entries' => [['min' => 1, 'max' => 6, 'text' => 'Aldric', 'sceneType' => 'tavern']]], $this->now);

        $draft->updateFromLibrary($pack, LibrarySection::Oracles, 'names', $this->now);

        self::assertSame(['oracles/names', 'tags/social', 'fact-slots/secret', 'scene-types/tavern'], $this->imported($draft));
        self::assertSame(['My alarm'], array_column($this->items($draft, 'trackers'), 'name'));
        self::assertSame(['tavern'], $this->keys($draft, 'sceneTypes'));
        ReleaseContent::checkItemRules($draft->document());
    }

    #[Test]
    public function anUpdateRefusesWhatIsNotImportedOrNotFromThatPackOrGoneFromThePack(): void
    {
        $pack = $this->pack();
        $draft = $this->draft();
        $draft->importFromLibrary($pack, [$this->ref(LibrarySection::Trackers, 'chaos')], $this->created);
        $draft->saveNarrativeItem(NarrativeSection::Tags, null, ['key' => 'plain', 'label' => 'Plain'], $this->created);
        $other = GameSystem::createLibraryPack(GameSystemId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a63'), 'other', 'user-1', 'Other', null, $this->created);

        $this->assertUntouched($draft, fn () => $draft->updateFromLibrary($pack, LibrarySection::Tags, 'plain', $this->now), LibraryImportNotFound::class);
        $this->assertUntouched($draft, fn () => $draft->updateFromLibrary($other, LibrarySection::Trackers, 'chaos', $this->now), LibraryImportNotFound::class);
        $this->assertUntouched($draft, fn () => $draft->updateFromLibrary($other, LibrarySection::Trackers, 'nope', $this->now), LibraryImportNotFound::class);

        $pack->deleteLikelihoodOracle('fate', $this->now);
        $pack->deleteNarrativeItem(NarrativeSection::Trackers, 'chaos', $this->now);

        $this->assertUntouched($draft, fn () => $draft->updateFromLibrary($pack, LibrarySection::Trackers, 'chaos', $this->now), LibraryItemRemoved::class);
    }

    #[Test]
    public function aFailingItemRuleRefusesAnUpdateAndKeepsTheOldCopyAndHash(): void
    {
        $pack = $this->pack();
        $draft = $this->draft();
        $draft->importFromLibrary($pack, [$this->ref(LibrarySection::FactSlots, 'secret')], $this->created);
        // The draft's own Scene Type fills the slot with a text, which only a text slot takes.
        $draft->saveNarrativeItem(NarrativeSection::SceneTypes, null, [
            'key' => 'whisper', 'name' => 'Whisper', 'purpose' => 'Hush.', 'oracles' => [], 'setup' => [], 'play' => [],
            'closing' => [['key' => 'tell', 'kind' => 'choice', 'title' => 'Tell?', 'options' => [['key' => 'yes', 'label' => 'Yes', 'effects' => [['kind' => 'fillFact', 'slot' => 'secret', 'text' => 'Hush']]], ['key' => 'no', 'label' => 'No']], 'skip' => 'no']],
        ], $this->created);
        // The pack's slot becomes an NPC slot.
        $pack->saveNarrativeItem(NarrativeSection::FactSlots, 'secret', ['key' => 'secret', 'label' => 'Secret', 'type' => 'npc'], $this->now);

        $this->assertUntouched($draft, fn () => $draft->updateFromLibrary($pack, LibrarySection::FactSlots, 'secret', $this->now), InvalidReleaseContent::class);
    }

    #[Test]
    public function aDetachForgetsTheImportAndKeepsTheItem(): void
    {
        $pack = $this->pack();
        $draft = $this->draft();
        $draft->importFromLibrary($pack, [$this->ref(LibrarySection::Tags, 'social'), $this->ref(LibrarySection::Trackers, 'chaos')], $this->created);

        $draft->detachLibraryImport(LibrarySection::Tags, 'social', $this->now);

        self::assertSame(['trackers/chaos'], $this->imported($draft));
        self::assertSame(['social'], $this->keys($draft, 'tags'));
        self::assertEquals($this->now, $draft->updatedAt());
        $this->expectException(LibraryImportNotFound::class);

        $draft->detachLibraryImport(LibrarySection::Tags, 'social', $this->now);
    }

    #[Test]
    public function detachingWhatWasNeverImportedIsRefused(): void
    {
        $draft = $this->draft();
        $draft->saveNarrativeItem(NarrativeSection::Tags, null, ['key' => 'plain', 'label' => 'Plain'], $this->created);

        $this->assertUntouched($draft, fn () => $draft->detachLibraryImport(LibrarySection::Tags, 'plain', $this->now), LibraryImportNotFound::class);
    }

    #[Test]
    public function deletingOrRenamingAnImportedItemDropsItsImportAndUnrelatedCommandsKeepIt(): void
    {
        $draft = $this->draft();
        $draft->importFromLibrary($this->pack(), [$this->ref(LibrarySection::Tags, 'social'), $this->ref(LibrarySection::Trackers, 'chaos'), $this->ref(LibrarySection::Oracles, 'names'), $this->ref(LibrarySection::FactSlots, 'secret')], $this->created);

        // Unrelated commands keep every import, whatever they touch.
        $draft->saveNarrativeItem(NarrativeSection::Tags, null, ['key' => 'plain', 'label' => 'Plain'], $this->now);
        $draft->saveSheetField(null, ['key' => 'hp', 'label' => 'HP', 'type' => 'number'], $this->now);
        $draft->saveNarrativeItem(NarrativeSection::Tags, 'social', ['key' => 'social', 'label' => 'Edited'], $this->now);
        self::assertSame(['oracles/names', 'trackers/chaos', 'tags/social', 'fact-slots/secret'], $this->imported($draft));

        $draft->deleteNarrativeItem(NarrativeSection::Tags, 'social', $this->now);
        self::assertSame(['oracles/names', 'trackers/chaos', 'fact-slots/secret'], $this->imported($draft));

        $draft->saveNarrativeItem(NarrativeSection::Trackers, 'chaos', ['key' => 'doom', 'name' => 'Doom', 'kind' => 'counter', 'min' => 1, 'max' => 9, 'initial' => 5], $this->now);
        self::assertSame(['oracles/names', 'fact-slots/secret'], $this->imported($draft));

        $draft->deleteOracleTable('names', $this->now);
        self::assertSame(['fact-slots/secret'], $this->imported($draft));

        $draft->restore(ReleaseContent::fromArray(ReleaseArrays::fixture('valid/minimal'))->toArray(), $this->now);
        self::assertSame([], $this->imported($draft));
        self::assertSame([], $draft->libraryImports());
    }

    #[Test]
    public function aRenamedItemKeepsNoImportEvenWhenAnotherItemTakesItsOldKey(): void
    {
        $draft = $this->draft();
        $draft->importFromLibrary($this->pack(), [$this->ref(LibrarySection::Tags, 'social')], $this->created);

        $draft->saveNarrativeItem(NarrativeSection::Tags, 'social', ['key' => 'crowd', 'label' => 'Crowd'], $this->now);
        $draft->saveNarrativeItem(NarrativeSection::Tags, null, ['key' => 'social', 'label' => 'Mine'], $this->now);

        self::assertSame([], $draft->libraryImports());
    }

    #[Test]
    public function metadataEditsKeepTheImports(): void
    {
        $draft = $this->draft();
        $draft->importFromLibrary($this->pack(), [$this->ref(LibrarySection::Tags, 'social')], $this->created);

        $draft->editMetadata(GameSystemMetadata::of('Renamed', null, null, null), $this->now);

        self::assertSame(['tags/social'], $this->imported($draft));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function malformedItemLists(): iterable
    {
        yield 'not a list' => [['section' => 'tags', 'key' => 'social'], 'items'];
        yield 'empty' => [[], 'items'];
        yield 'not an object' => [['tags'], 'items[0].section'];
        yield 'unknown section' => [[['section' => 'flows', 'key' => 'main']], 'items[0].section'];
        yield 'section not a text' => [[['section' => 3, 'key' => 'main']], 'items[0].section'];
        yield 'missing key' => [[['section' => 'tags']], 'items[0].key'];
        yield 'key not a text' => [[['section' => 'tags', 'key' => ['x']]], 'items[0].key'];
        yield 'second item is bad' => [[['section' => 'tags', 'key' => 'a'], ['section' => 'nope', 'key' => 'b']], 'items[1].section'];
        yield 'null' => [null, 'items'];
    }

    #[Test]
    #[DataProvider('malformedItemLists')]
    public function aMalformedRequestedItemListFailsClosedWithItsPath(mixed $items, string $path): void
    {
        try {
            LibraryItemRef::listFromArray($items);
            self::fail('The list was accepted.');
        } catch (InvalidReleaseContent $invalid) {
            self::assertStringStartsWith($path.':', $invalid->getMessage());
        }
    }

    #[Test]
    public function aWellFormedRequestedItemListReadsAsRefs(): void
    {
        $refs = LibraryItemRef::listFromArray([['section' => 'fact-slots', 'key' => 'secret'], ['section' => 'oracles', 'key' => 'names']]);

        self::assertEquals([$this->ref(LibrarySection::FactSlots, 'secret'), $this->ref(LibrarySection::Oracles, 'names')], $refs);
        self::assertSame([['section' => 'fact-slots', 'key' => 'secret'], ['section' => 'oracles', 'key' => 'names']], array_map(static fn (LibraryItemRef $ref): array => $ref->toArray(), $refs));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedStoredImports(): iterable
    {
        yield 'not a list' => [['section' => 'tags']];
        yield 'a scalar' => ['tags'];
        yield 'null' => [null];
        yield 'an item that is not an object' => [['tags']];
        yield 'unknown section' => [[['section' => 'flows', 'key' => 'a', 'pack' => 'p', 'hash' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']]];
        yield 'missing pack' => [[['section' => 'tags', 'key' => 'a', 'hash' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']]];
        yield 'key not a text' => [[['section' => 'tags', 'key' => 1, 'pack' => 'p', 'hash' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']]];
        yield 'hash too short' => [[['section' => 'tags', 'key' => 'a', 'pack' => 'p', 'hash' => 'abc']]];
        yield 'hash not hex' => [[['section' => 'tags', 'key' => 'a', 'pack' => 'p', 'hash' => 'zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz']]];
    }

    #[Test]
    #[DataProvider('malformedStoredImports')]
    public function malformedStoredImportsFailClosed(mixed $stored): void
    {
        $this->expectException(\UnexpectedValueException::class);

        LibraryImport::listFromStored($stored);
    }

    #[Test]
    public function aDraftWithMalformedStoredImportsRefusesToActOnThem(): void
    {
        $draft = $this->draft();
        new \ReflectionProperty(GameSystem::class, 'libraryImports')->setValue($draft, [['section' => 'tags']]);

        $document = $draft->document();
        try {
            $draft->saveNarrativeItem(NarrativeSection::Tags, null, ['key' => 'plain', 'label' => 'Plain'], $this->now);
            self::fail('The command was accepted.');
        } catch (\Throwable $refusal) {
            self::assertInstanceOf(\UnexpectedValueException::class, $refusal);
            self::assertSame($document, $draft->document());
        }
    }
}
