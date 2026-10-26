<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Application;

use App\Studio\Application\DeleteNarrativeItem;
use App\Studio\Application\DeleteNarrativeItemHandler;
use App\Studio\Application\GameSystemNarrativeView;
use App\Studio\Application\GetGameSystemNarrative;
use App\Studio\Application\GetGameSystemNarrativeHandler;
use App\Studio\Application\NarrativeItemView;
use App\Studio\Application\OwnedGameSystems;
use App\Studio\Application\SaveNarrativeItem;
use App\Studio\Application\SaveNarrativeItemHandler;
use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\GameSystemId;
use App\Studio\Domain\GameSystem\GameSystemModifiedConcurrently;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\GameSystem\NarrativeItemInUse;
use App\Studio\Domain\GameSystem\NarrativeItemNotFound;
use App\Studio\Domain\GameSystem\NarrativeKeyTaken;
use App\Studio\Domain\GameSystem\NarrativeSection;
use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Tests\Support\Studio\FixedClock;
use App\Tests\Support\Studio\InMemoryGameSystemRepository;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SaveNarrativeItemHandler::class)]
#[CoversClass(DeleteNarrativeItemHandler::class)]
#[CoversClass(GetGameSystemNarrativeHandler::class)]
final class GameSystemNarrativeHandlersTest extends TestCase
{
    private InMemoryGameSystemRepository $gameSystems;
    private OwnedGameSystems $owned;
    private FixedClock $clock;
    private int $drafts = 0;

    protected function setUp(): void
    {
        $this->gameSystems = new InMemoryGameSystemRepository();
        $this->owned = new OwnedGameSystems($this->gameSystems);
        $this->clock = new FixedClock('2026-10-16T10:00:00+00:00');
    }

    #[Test]
    public function theReadModelListsEverySectionInOrderWithItsVersionAndUsage(): void
    {
        $this->draft('my-heist');

        $view = $this->narrative('ann', 'my-heist');

        self::assertSame([1, 2], [$view->version, $view->schemaVersion]);
        self::assertSame(['alarm', 'edge', 'chaos', 'heat'], $this->keys($view->trackers));
        self::assertSame([], $view->tags);
        self::assertSame(['target', 'client', 'ambition'], $this->keys($view->factSlots));
        self::assertSame(['legwork', 'infiltration', 'firefight', 'getaway', 'downtime'], $this->keys($view->sceneTypes));
        self::assertSame(['heist', 'quick'], $this->keys($view->flows));
        self::assertSame('Chaos factor', $view->trackers[2]->definition['name']);
        self::assertSame(['flows[0].trackers', 'oracles.likelihood[0].chaos'], $view->trackers[2]->usedBy);
        self::assertSame(['flows[0].phases[2].selection', 'oracles.tables[1].entries[3]'], $view->sceneTypes[3]->usedBy);
        self::assertSame([], $view->sceneTypes[4]->usedBy);
        self::assertSame([], $view->flows[0]->usedBy);
    }

    #[Test]
    public function theCatalogOffersThePickersOfTheOtherSections(): void
    {
        $this->draft('my-heist');

        $catalog = $this->narrative('ann', 'my-heist')->catalog;

        self::assertSame(['heist-scenes', 'complications', 'escape-routes'], array_column($catalog->tables, 'key'));
        self::assertSame('Complications', $catalog->tables[1]['name']);
        self::assertSame(['patrol', 'lucky-break', 'spotted', 'run'], $catalog->tables[1]['entries']);
        self::assertSame([['key' => 'fate', 'name' => 'Fate question', 'levels' => ['unlikely', 'even', 'likely']]], $catalog->likelihood);
        self::assertSame([], $catalog->checks);
        self::assertSame([], $catalog->fields);
    }

    #[Test]
    public function theCatalogListsChecksWithBandsAndDifficultyAndTheNumberAndTrackFields(): void
    {
        $this->draft('my-skills', 'valid/v2-checks');
        $this->draft('my-sheet', 'valid/v2-sheet-effects');

        $checks = $this->narrative('ann', 'my-skills')->catalog;

        self::assertSame([
            ['key' => 'skill', 'label' => 'Skill check', 'bands' => ['fail', 'success', 'crit'], 'difficulty' => ['default' => 13, 'min' => 9, 'max' => 29]],
            ['key' => 'luck', 'label' => 'Luck', 'bands' => ['bad', 'ok', 'good'], 'difficulty' => null],
        ], $checks->checks);
        self::assertSame([['key' => 'hands', 'label' => 'Hands', 'options' => null]], $checks->fields);
        self::assertSame([['key' => 'pow', 'label' => 'Power', 'options' => null], ['key' => 'hp', 'label' => 'Hit points', 'options' => null], ['key' => 'sanity', 'label' => 'Sanity', 'options' => null]], $this->narrative('ann', 'my-sheet')->catalog->fields);
    }

    #[Test]
    public function theCatalogListsAConditionsFieldWithItsOptionsBesideTheNumberAndTrackFields(): void
    {
        $this->draft('my-pools', 'valid/v2-dice-pools');

        self::assertSame([
            ['key' => 'agility', 'label' => 'Agility', 'options' => null],
            ['key' => 'stealth', 'label' => 'Stealth', 'options' => null],
            ['key' => 'physical', 'label' => 'Physical conditions', 'options' => [['key' => 'hurt', 'label' => 'Hurt'], ['key' => 'exhausted', 'label' => 'Exhausted']]],
            ['key' => 'mental', 'label' => 'Mental conditions', 'options' => [['key' => 'shaken', 'label' => 'Shaken']]],
        ], $this->narrative('ann', 'my-pools')->catalog->fields);
    }

    #[Test]
    public function aSchemaVersion1DraftReadsAsEmptySectionsWithItsOracles(): void
    {
        $this->draft('my-journal', 'valid/contract-doc-example');

        $view = $this->narrative('ann', 'my-journal');

        self::assertSame(1, $view->schemaVersion);
        self::assertSame([[], [], [], [], []], [$view->trackers, $view->tags, $view->factSlots, $view->sceneTypes, $view->flows]);
        self::assertSame(['weather', 'storm-kind'], array_column($view->catalog->tables, 'key'));
        self::assertSame([], $view->catalog->checks);
    }

    #[Test]
    public function aMalformedDraftIsReadDefensively(): void
    {
        $draft = $this->draft('broken');
        $document = ReleaseArrays::with($draft->document(), 'trackers', ['text', ['name' => 'No key'], ['key' => 'ok', 'kind' => 'clock'], ['key' => 7]]);
        $document = ReleaseArrays::with($document, 'flows', 'nope');
        $document = ReleaseArrays::with($document, 'tags', ['a' => 1]);
        $document = ReleaseArrays::with($document, 'oracles', ['tables' => ['x', ['key' => 'big', 'name' => 3, 'entries' => [['key' => 'a'], 'y', ['text' => 'no key']]]], 'likelihood' => [['key' => 'l', 'levels' => 'z'], 4]]);
        $document = ReleaseArrays::with($document, 'checks', [['key' => 'c', 'label' => 'C', 'bands' => [['key' => 'b'], 1], 'difficulty' => 'hard'], 'z', ['label' => 'No key']]);
        $document = ReleaseArrays::with($document, 'sheet', ['fields' => [['key' => 'f', 'label' => 'F', 'type' => 'number'], ['key' => 'g', 'type' => 'track'], 'x', ['type' => 'number'], ['key' => 'h', 'type' => 'conditions', 'options' => [['key' => 'o', 'label' => 'O'], ['key' => 'p', 'label' => 3], 'z', ['label' => 'No key']]], ['key' => 'i', 'type' => 'conditions', 'options' => 'none'], ['key' => 'j', 'type' => 'conditions']]]);
        $draft->restore($document, $this->clock->now());

        $view = $this->narrative('ann', 'broken');

        self::assertSame(['ok'], $this->keys($view->trackers));
        self::assertSame([[], []], [$view->flows, $view->tags]);
        self::assertSame([['key' => 'big', 'name' => '', 'entries' => ['a']]], $view->catalog->tables);
        self::assertSame([['key' => 'l', 'name' => '', 'levels' => []]], $view->catalog->likelihood);
        self::assertSame([['key' => 'c', 'label' => 'C', 'bands' => ['b'], 'difficulty' => null]], $view->catalog->checks);
        self::assertSame([
            ['key' => 'f', 'label' => 'F', 'options' => null],
            ['key' => 'g', 'label' => '', 'options' => null],
            ['key' => 'h', 'label' => '', 'options' => [['key' => 'o', 'label' => 'O'], ['key' => 'p', 'label' => '']]],
            ['key' => 'i', 'label' => '', 'options' => []],
            ['key' => 'j', 'label' => '', 'options' => []],
        ], $view->catalog->fields);
    }

    #[Test]
    public function aNewItemIsSavedRenamedAndDeleted(): void
    {
        $this->draft('my-heist');

        $this->save(NarrativeSection::Tags, null, ['key' => 'gang', 'label' => 'Gang']);
        $this->save(NarrativeSection::Tags, 'gang', ['key' => 'crew', 'label' => 'Crew'], version: 2);

        self::assertSame(['crew'], $this->keys($this->narrative('ann', 'my-heist')->tags));
        self::assertSame(3, $this->narrative('ann', 'my-heist')->version);

        $this->delete(NarrativeSection::Tags, 'crew', version: 3);

        self::assertSame([], $this->narrative('ann', 'my-heist')->tags);
        self::assertEquals($this->clock->now(), $this->gameSystems->ofKey('my-heist')?->updatedAt());
    }

    /**
     * @return iterable<string, array{\Closure(self): void}>
     */
    public static function commands(): iterable
    {
        yield 'save' => [static fn (self $t, string $user = 'ann', int $version = 1) => $t->save(NarrativeSection::Tags, null, ['key' => 'gang', 'label' => 'Gang'], $user, $version)];
        yield 'delete' => [static fn (self $t, string $user = 'ann', int $version = 1) => $t->delete(NarrativeSection::FactSlots, 'target', $user, $version)];
    }

    /**
     * @param \Closure(self, string, int): void $command
     */
    #[Test]
    #[DataProvider('commands')]
    public function aStaleExpectedVersionIsRefusedAndChangesNothing(\Closure $command): void
    {
        $draft = $this->draft('my-heist');

        $this->expectRefusal(GameSystemModifiedConcurrently::class, $draft, fn () => $command($this, 'ann', 2));
    }

    /**
     * @param \Closure(self, string, int): void $command
     */
    #[Test]
    #[DataProvider('commands')]
    public function anotherOwnersDraftIsNotFound(\Closure $command): void
    {
        $draft = $this->draft('my-heist');

        $this->expectRefusal(GameSystemNotFound::class, $draft, fn () => $command($this, 'bob', 1));
    }

    #[Test]
    public function theReadModelOfAnotherOwnersOrAMissingDraftIsNotFound(): void
    {
        $this->draft('my-heist');

        $refused = [];
        foreach ([['bob', 'my-heist'], ['ann', 'nothing']] as [$user, $key]) {
            try {
                $this->narrative($user, $key);
            } catch (GameSystemNotFound) {
                $refused[] = $user;
            }
        }

        self::assertSame(['bob', 'ann'], $refused);
    }

    /**
     * @return iterable<string, array{\Closure(self): void, class-string<\Throwable>}>
     */
    public static function refusedCommands(): iterable
    {
        yield 'an invalid item' => [static fn (self $t) => $t->save(NarrativeSection::Tags, null, ['key' => 'Not A Key', 'label' => 'Gang']), InvalidReleaseContent::class];
        yield 'a taken key' => [static fn (self $t) => $t->save(NarrativeSection::Trackers, null, ['key' => 'alarm', 'name' => 'Alarm', 'kind' => 'clock', 'segments' => 4]), NarrativeKeyTaken::class];
        yield 'a missing original key' => [static fn (self $t) => $t->save(NarrativeSection::Tags, 'nowhere', ['key' => 'gang', 'label' => 'Gang']), NarrativeItemNotFound::class];
        yield 'deleting a missing item' => [static fn (self $t) => $t->delete(NarrativeSection::Flows, 'nowhere'), NarrativeItemNotFound::class];
        yield 'deleting a referenced Scene Type' => [static fn (self $t) => $t->delete(NarrativeSection::SceneTypes, 'getaway'), NarrativeItemInUse::class];
        yield 'renaming a referenced tracker' => [static fn (self $t) => $t->save(NarrativeSection::Trackers, 'heat', ['key' => 'warmth', 'name' => 'Heat', 'kind' => 'clock', 'segments' => 4]), NarrativeItemInUse::class];
    }

    /**
     * @param \Closure(self): void     $command
     * @param class-string<\Throwable> $refusal
     */
    #[Test]
    #[DataProvider('refusedCommands')]
    public function aRefusedCommandLeavesTheStoredDraftUntouched(\Closure $command, string $refusal): void
    {
        $draft = $this->draft('my-heist');
        $updatedAt = $draft->updatedAt();

        $this->expectRefusal($refusal, $draft, fn () => $command($this));

        self::assertEquals($updatedAt, $draft->updatedAt());
    }

    #[Test]
    public function aSchemaVersion1DraftRefusesEveryNarrativeCommand(): void
    {
        $draft = $this->draft('my-journal', 'valid/contract-doc-example');

        $this->expectRefusal(InvalidReleaseContent::class, $draft, fn () => $this->save(NarrativeSection::Tags, null, ['key' => 'gang', 'label' => 'Gang'], key: 'my-journal'));
        $this->expectRefusal(InvalidReleaseContent::class, $draft, fn () => $this->delete(NarrativeSection::Flows, 'heist', key: 'my-journal'));
    }

    /**
     * @param class-string<\Throwable> $refusal
     */
    private function expectRefusal(string $refusal, GameSystem $draft, \Closure $command): void
    {
        $before = $draft->document();
        $version = $draft->version();

        try {
            $command();
            self::fail('Expected '.$refusal);
        } catch (\Throwable $thrown) {
            self::assertInstanceOf($refusal, $thrown);
        }

        self::assertSame($before, $draft->document());
        self::assertSame($version, $draft->version());
    }

    /**
     * @param array<mixed> $item
     */
    public function save(NarrativeSection $section, ?string $originalKey, array $item, string $user = 'ann', int $version = 1, string $key = 'my-heist'): void
    {
        (new SaveNarrativeItemHandler($this->owned, $this->gameSystems, $this->clock))(new SaveNarrativeItem($user, $key, $version, $section, $originalKey, $item));
    }

    public function delete(NarrativeSection $section, string $itemKey, string $user = 'ann', int $version = 1, string $key = 'my-heist'): void
    {
        (new DeleteNarrativeItemHandler($this->owned, $this->gameSystems, $this->clock))(new DeleteNarrativeItem($user, $key, $version, $section, $itemKey));
    }

    private function draft(string $key, string $fixture = 'valid/v2-every-part'): GameSystem
    {
        $source = ReleaseContent::fromArray(ReleaseArrays::fixture($fixture))->toArray();
        $draft = GameSystem::createFromRelease(GameSystemId::fromString(\sprintf('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f%04x', ++$this->drafts)), $key, 'Draft', null, 'ann', $source, $this->clock->now());
        $this->gameSystems->add($draft);

        return $draft;
    }

    private function narrative(string $user, string $key): GameSystemNarrativeView
    {
        return (new GetGameSystemNarrativeHandler($this->owned))(new GetGameSystemNarrative($user, $key));
    }

    /**
     * @param list<NarrativeItemView> $views
     *
     * @return list<string>
     */
    private function keys(array $views): array
    {
        return array_map(static fn (NarrativeItemView $view): string => $view->key, $views);
    }
}
