<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Application;

use App\Studio\Application\DeclareSheetLayout;
use App\Studio\Application\DeclareSheetLayoutHandler;
use App\Studio\Application\DeleteSheetField;
use App\Studio\Application\DeleteSheetFieldHandler;
use App\Studio\Application\GameSystemSheetView;
use App\Studio\Application\GetGameSystemSheet;
use App\Studio\Application\GetGameSystemSheetHandler;
use App\Studio\Application\MoveSheetField;
use App\Studio\Application\MoveSheetFieldHandler;
use App\Studio\Application\OwnedGameSystems;
use App\Studio\Application\RemoveSheetLayout;
use App\Studio\Application\RemoveSheetLayoutHandler;
use App\Studio\Application\RemoveSheetPointBudgetEntry;
use App\Studio\Application\RemoveSheetPointBudgetEntryHandler;
use App\Studio\Application\SaveSheetField;
use App\Studio\Application\SaveSheetFieldHandler;
use App\Studio\Application\SaveSheetLayout;
use App\Studio\Application\SaveSheetLayoutHandler;
use App\Studio\Application\SaveSheetPointBudgetEntry;
use App\Studio\Application\SaveSheetPointBudgetEntryHandler;
use App\Studio\Application\SheetGroupView;
use App\Studio\Application\SheetPointBudgetView;
use App\Studio\Application\SheetTrackerView;
use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\GameSystemId;
use App\Studio\Domain\GameSystem\GameSystemModifiedConcurrently;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\GameSystem\SheetFieldInUse;
use App\Studio\Domain\GameSystem\SheetFieldKeyTaken;
use App\Studio\Domain\GameSystem\SheetFieldNotFound;
use App\Studio\Domain\GameSystem\SheetPointBudgetNotFound;
use App\Studio\Domain\Release\GameSystemRelease;
use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\ReleaseId;
use App\Tests\Support\Studio\FixedClock;
use App\Tests\Support\Studio\InMemoryGameSystemReleaseRepository;
use App\Tests\Support\Studio\InMemoryGameSystemRepository;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Drafts copy valid/v2-sheet-effects: fields pow, hp, sanity and bonus; a dread clock of 6 segments.
 */
#[CoversClass(SaveSheetFieldHandler::class)]
#[CoversClass(DeleteSheetFieldHandler::class)]
#[CoversClass(MoveSheetFieldHandler::class)]
#[CoversClass(SaveSheetLayoutHandler::class)]
#[CoversClass(DeclareSheetLayoutHandler::class)]
#[CoversClass(RemoveSheetLayoutHandler::class)]
#[CoversClass(SaveSheetPointBudgetEntryHandler::class)]
#[CoversClass(RemoveSheetPointBudgetEntryHandler::class)]
#[CoversClass(GetGameSystemSheetHandler::class)]
final class GameSystemSheetHandlersTest extends TestCase
{
    private InMemoryGameSystemRepository $gameSystems;
    private InMemoryGameSystemReleaseRepository $releases;
    private OwnedGameSystems $owned;
    private FixedClock $clock;
    private int $drafts = 0;

    protected function setUp(): void
    {
        $this->gameSystems = new InMemoryGameSystemRepository();
        $this->releases = new InMemoryGameSystemReleaseRepository();
        $this->owned = new OwnedGameSystems($this->gameSystems);
        $this->clock = new FixedClock('2026-10-16T10:00:00+00:00');
    }

    #[Test]
    public function theReadModelOfASheetWithoutLayoutListsFieldsTrackersAndNoPublishedKeys(): void
    {
        $this->draft('my-sheet');

        $view = $this->sheet('ann', 'my-sheet');

        self::assertSame([1, 2, 1, null], [$view->version, $view->schemaVersion, $view->columns, $view->groups]);
        self::assertSame(['pow', 'hp', 'sanity', 'bonus'], array_column($view->fields, 'key'));
        self::assertSame('Power', $view->fields[0]['label']);
        self::assertEquals([new SheetTrackerView('dread', 'Dread', 6)], $view->trackers);
        self::assertSame([], $view->publishedFieldKeys);
    }

    #[Test]
    public function theReadModelOfASheetWithLayoutFillsTheDefaults(): void
    {
        $draft = $this->draft('my-sheet');
        $draft->restore(ReleaseArrays::with($draft->document(), 'sheet', [
            'columns' => 2,
            'groups' => [['name' => 'Stats', 'columns' => 3, 'span' => 2], ['name' => 'Gear']],
            'fields' => [['key' => 'pow', 'label' => 'Power', 'group' => 'Stats', 'type' => 'number']],
        ]), $this->clock->now());

        $view = $this->sheet('ann', 'my-sheet');

        self::assertSame(2, $view->columns);
        self::assertEquals([new SheetGroupView('Stats', 3, 2), new SheetGroupView('Gear', 1, 1)], $view->groups);
        self::assertSame(['pow'], array_column($view->fields, 'key'));
    }

    #[Test]
    public function thePublishedFieldKeysAreThoseOfTheLatestRelease(): void
    {
        $draft = $this->draft('my-sheet');
        $this->release($draft, 1, ['pow']);
        $this->release($draft, 2, ['pow', 'hp', 'gone']);
        $draft->saveSheetField(null, ['key' => 'luck', 'label' => 'Luck', 'type' => 'number'], $this->clock->now());
        $this->releases->add(GameSystemRelease::publish(ReleaseId::fromString('other-system'), ReleaseContent::fromArray(ReleaseArrays::fixture('valid/v2-sheet')), 1, $this->clock->now()));

        $view = $this->sheet('ann', 'my-sheet');

        self::assertSame(['pow', 'hp', 'gone'], $view->publishedFieldKeys);
        self::assertContains('luck', array_column($view->fields, 'key'));
    }

    #[Test]
    public function aReleaseWithoutASheetHasNoPublishedKeys(): void
    {
        $this->draft('my-sheet');
        $this->releases->add(GameSystemRelease::publish(ReleaseId::fromString('r-1'), ReleaseContent::fromArray(ReleaseArrays::fixture('valid/v2-minimal')), 1, $this->clock->now()));

        self::assertSame([], $this->sheet('ann', 'my-sheet')->publishedFieldKeys);
    }

    #[Test]
    public function aSchemaVersion1DraftReadsAsAnEmptySheet(): void
    {
        $this->draft('my-journal', 'valid/contract-doc-example');

        $view = $this->sheet('ann', 'my-journal');

        self::assertSame([1, 1, null, [], [], []], [$view->schemaVersion, $view->columns, $view->groups, $view->fields, $view->trackers, $view->publishedFieldKeys]);
    }

    #[Test]
    public function aMalformedDraftIsReadDefensively(): void
    {
        $draft = $this->draft('broken');
        $document = ReleaseArrays::with($draft->document(), 'sheet', [
            'columns' => 'two',
            'groups' => ['x', ['columns' => 2], ['name' => 'Ok', 'columns' => 'wide', 'span' => null]],
            'fields' => ['text', ['label' => 'No key'], ['key' => 7], ['key' => 'ok', 'type' => 'text']],
        ]);
        $document = ReleaseArrays::with($document, 'trackers', ['t', ['name' => 'No key'], ['key' => 'a'], ['key' => 'b', 'name' => 4, 'max' => 'x', 'segments' => 3], ['key' => 'c', 'name' => 'C', 'max' => 9]]);
        $draft->restore($document, $this->clock->now());

        $view = $this->sheet('ann', 'broken');

        self::assertSame(1, $view->columns);
        self::assertEquals([new SheetGroupView('Ok', 1, 1)], $view->groups);
        self::assertSame([['key' => 'ok', 'type' => 'text']], $view->fields);
        self::assertEquals([new SheetTrackerView('a', '', null), new SheetTrackerView('b', '', 3), new SheetTrackerView('c', 'C', 9)], $view->trackers);
    }

    #[Test]
    public function aSheetThatIsNotAnObjectOrGroupsThatAreNotAListReadAsEmpty(): void
    {
        $draft = $this->draft('broken');
        foreach ([['sheet' => 'nope'], ['sheet' => ['fields' => 'x', 'groups' => 'y']]] as $sheet) {
            $draft->restore(ReleaseArrays::with($draft->document(), 'sheet', $sheet['sheet']), $this->clock->now());

            $view = $this->sheet('ann', 'broken');

            self::assertSame([1, null, []], [$view->columns, $view->groups, $view->fields]);
        }
    }

    #[Test]
    public function aFieldIsSavedRenamedMovedAndDeleted(): void
    {
        $this->draft('my-sheet');

        $this->save(null, ['key' => 'luck', 'label' => 'Luck', 'type' => 'number']);
        $this->save('luck', ['key' => 'fortune', 'label' => 'Fortune', 'type' => 'number'], version: 2);
        $this->move('fortune', 0, version: 3);

        $view = $this->sheet('ann', 'my-sheet');
        self::assertSame(['fortune', 'pow', 'hp', 'sanity', 'bonus'], array_column($view->fields, 'key'));
        self::assertSame(4, $view->version);

        $this->delete('fortune', version: 4);

        self::assertSame(['pow', 'hp', 'sanity', 'bonus'], array_column($this->sheet('ann', 'my-sheet')->fields, 'key'));
        self::assertSame(5, $this->sheet('ann', 'my-sheet')->version);
        self::assertEquals($this->clock->now(), $this->gameSystems->ofKey('my-sheet')?->updatedAt());
    }

    #[Test]
    public function theLayoutIsDeclaredSavedWithARenameAndRemoved(): void
    {
        $this->draft('my-sheet');

        $this->declareLayout();
        $view = $this->sheet('ann', 'my-sheet');
        self::assertEquals([new SheetGroupView('General', 1, 1)], $view->groups);
        self::assertSame(2, $view->version);

        $this->saveLayout(2, [['name' => 'Core', 'previousName' => 'General', 'columns' => 2], ['name' => 'Extras']], version: 2);
        $view = $this->sheet('ann', 'my-sheet');
        self::assertSame(2, $view->columns);
        self::assertEquals([new SheetGroupView('Core', 2, 1), new SheetGroupView('Extras', 1, 1)], $view->groups);
        self::assertSame(['Core'], $this->groupsOf($view));
        self::assertSame(3, $view->version);

        $this->removeLayout(version: 3);
        $view = $this->sheet('ann', 'my-sheet');
        self::assertSame([1, null, 4], [$view->columns, $view->groups, $view->version]);
        self::assertSame(['Core'], $this->groupsOf($view));
        self::assertEquals($this->clock->now(), $this->gameSystems->ofKey('my-sheet')?->updatedAt());
    }

    /**
     * @return iterable<string, array{\Closure(self, string, int): void}>
     */
    public static function commands(): iterable
    {
        yield 'save' => [static fn (self $t, string $user, int $version) => $t->save(null, ['key' => 'luck', 'label' => 'Luck', 'type' => 'number'], $user, $version)];
        yield 'delete' => [static fn (self $t, string $user, int $version) => $t->delete('bonus', $user, $version)];
        yield 'move' => [static fn (self $t, string $user, int $version) => $t->move('bonus', 0, $user, $version)];
        yield 'save layout' => [static fn (self $t, string $user, int $version) => $t->saveLayout(1, [['name' => 'General']], $user, $version)];
        yield 'declare layout' => [static fn (self $t, string $user, int $version) => $t->declareLayout($user, $version)];
        yield 'remove layout' => [static fn (self $t, string $user, int $version) => $t->removeLayout($user, $version)];
        yield 'save point budget' => [static fn (self $t, string $user, int $version) => $t->saveBudgetEntry('points', ['total' => 100, 'fields' => ['pow']], $user, $version)];
    }

    #[Test]
    public function aSheetWithoutAPointBudgetReadsAnEmptyList(): void
    {
        $this->draft('my-sheet');

        self::assertSame([], $this->sheet('ann', 'my-sheet')->pointBudgets);
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, non-empty-string}> the budget and where the refusal points
     */
    public static function invalidBudgets(): iterable
    {
        yield 'no total' => [['fields' => ['pow']], 'sheet.pointBudgets[0].total'];
        yield 'a total that is not a number' => [['total' => '100', 'fields' => ['pow']], 'sheet.pointBudgets[0].total'];
        yield 'a total of zero' => [['total' => 0, 'fields' => ['pow']], 'sheet.pointBudgets[0].total'];
        yield 'no fields' => [['total' => 100], 'sheet.pointBudgets[0].fields'];
        yield 'fields that are not a list' => [['total' => 100, 'fields' => 'pow'], 'sheet.pointBudgets[0].fields'];
        yield 'no field listed' => [['total' => 100, 'fields' => []], 'sheet.pointBudgets[0].fields'];
        yield 'an unknown field' => [['total' => 100, 'fields' => ['nowhere']], 'sheet.pointBudgets[0].fields[0]'];
        yield 'a field that is not a number' => [['total' => 100, 'fields' => ['pow', 'hp']], 'sheet.pointBudgets[0].fields[1]'];
        yield 'a repeated field' => [['total' => 100, 'fields' => ['pow', 'pow']], 'sheet.pointBudgets[0].fields[1]'];
        yield 'min above max' => [['total' => 100, 'fields' => ['pow'], 'min' => 50, 'max' => 40], 'sheet.pointBudgets[0].max'];
        yield 'bounds no value fits' => [['total' => 100, 'fields' => ['pow'], 'min' => 100], 'sheet.pointBudgets[0].fields[0]'];
        yield 'a total below the lowest values' => [['total' => 10, 'fields' => ['pow'], 'min' => 20], 'sheet.pointBudgets[0].total'];
        yield 'a label that is too long' => [['label' => str_repeat('x', 61), 'total' => 100, 'fields' => ['pow']], 'sheet.pointBudgets[0].label'];
        yield 'an unknown property' => [['total' => 100, 'fields' => ['pow'], 'step' => 5], 'sheet.pointBudgets[0]'];
    }

    /**
     * @param array<array-key, mixed> $budget
     * @param non-empty-string        $path
     */
    #[Test]
    #[DataProvider('invalidBudgets')]
    public function anInvalidPointBudgetIsRefusedWithItsPathAndChangesNothing(array $budget, string $path): void
    {
        $draft = $this->draft('my-sheet');
        $before = $draft->document();

        try {
            $this->saveBudgetEntry('points', ['label' => 'Points', ...$budget]);
            self::fail('The budget was accepted.');
        } catch (InvalidReleaseContent $invalid) {
            self::assertStringStartsWith($path, $invalid->getMessage());
        }

        self::assertNull(self::storedBudget($draft));
        self::assertSame($before, $draft->document());
        self::assertSame(1, $draft->version());
    }

    #[Test]
    public function severalPointBudgetsAreReadInOrderAndRemovedOneByOne(): void
    {
        $draft = $this->draft('my-sheet');
        $this->save(null, ['key' => 'luck', 'label' => 'Luck', 'type' => 'number', 'min' => 0, 'max' => 10]);

        $this->saveBudgetEntry('attributes', ['label' => 'Attribute points', 'total' => 100, 'fields' => ['pow'], 'min' => 10], version: 2);
        self::assertEquals(new SheetPointBudgetView('attributes', 'Attribute points', 100, ['pow'], 10, null), $this->sheet('ann', 'my-sheet')->pointBudgets[0]);

        $this->saveBudgetEntry('skills', ['label' => 'Skill points', 'total' => 8, 'fields' => ['luck'], 'max' => 3], version: 3);
        $view = $this->sheet('ann', 'my-sheet');
        self::assertEquals([new SheetPointBudgetView('attributes', 'Attribute points', 100, ['pow'], 10, null), new SheetPointBudgetView('skills', 'Skill points', 8, ['luck'], null, 3)], $view->pointBudgets);

        $this->removeBudgetEntry('attributes', version: 4);
        self::assertEquals([new SheetPointBudgetView('skills', 'Skill points', 8, ['luck'], null, 3)], $this->sheet('ann', 'my-sheet')->pointBudgets);
        $this->removeBudgetEntry('skills', version: 5);
        self::assertSame([], $this->sheet('ann', 'my-sheet')->pointBudgets);
        self::assertSame(['pow', 'hp', 'sanity', 'bonus', 'luck'], array_column($this->sheet('ann', 'my-sheet')->fields, 'key'));
        self::assertSame(6, $draft->version());
    }

    #[Test]
    public function aLegacyBudgetReadsAsTheKeyPoints(): void
    {
        $draft = $this->draft('my-sheet');
        $sheet = (array) $draft->document()['sheet'];
        $draft->restore(ReleaseArrays::with($draft->document(), 'sheet', [...$sheet, 'pointBudget' => ['total' => 40, 'fields' => ['pow']]]), $this->clock->now());

        self::assertEquals([new SheetPointBudgetView('points', null, 40, ['pow'], null, null)], $this->sheet('ann', 'my-sheet')->pointBudgets);
    }

    #[Test]
    public function theKeyedCommandsRefuseAsTheDomainDoesAndChangeNothing(): void
    {
        $draft = $this->draft('my-sheet');
        $this->saveBudgetEntry('attributes', ['label' => 'Attribute points', 'total' => 100, 'fields' => ['pow']]);

        $this->expectRefusal(SheetPointBudgetNotFound::class, $draft, fn () => $this->removeBudgetEntry('skills', version: 2));
        $this->expectRefusal(InvalidReleaseContent::class, $draft, fn () => $this->saveBudgetEntry('skills', ['label' => 'Again', 'total' => 100, 'fields' => ['pow']], version: 2));
        $this->expectRefusal(GameSystemModifiedConcurrently::class, $draft, fn () => $this->saveBudgetEntry('skills', ['label' => 'Skills', 'total' => 1, 'fields' => ['hp']], version: 1));
        $this->expectRefusal(GameSystemNotFound::class, $draft, fn () => $this->removeBudgetEntry('attributes', user: 'bob', version: 2));
    }

    #[Test]
    public function aFieldOfThePointBudgetCannotBeDeletedAfterwards(): void
    {
        $draft = $this->draft('my-sheet');
        $this->save(null, ['key' => 'luck', 'label' => 'Luck', 'type' => 'number']);
        $this->saveBudgetEntry('points', ['label' => 'Points', 'total' => 100, 'fields' => ['luck']], version: 2);

        $this->expectRefusal(SheetFieldInUse::class, $draft, fn () => $this->delete('luck', version: 3));
    }

    /**
     * @param \Closure(self, string, int): void $command
     */
    #[Test]
    #[DataProvider('commands')]
    public function aStaleExpectedVersionIsRefusedAndChangesNothing(\Closure $command): void
    {
        $draft = $this->draft('my-sheet');

        $this->expectRefusal(GameSystemModifiedConcurrently::class, $draft, fn () => $command($this, 'ann', 2));
    }

    /**
     * @param \Closure(self, string, int): void $command
     */
    #[Test]
    #[DataProvider('commands')]
    public function anotherOwnersDraftIsNotFound(\Closure $command): void
    {
        $draft = $this->draft('my-sheet');

        $this->expectRefusal(GameSystemNotFound::class, $draft, fn () => $command($this, 'bob', 1));
    }

    #[Test]
    public function theReadModelOfAnotherOwnersOrAMissingDraftIsNotFound(): void
    {
        $this->draft('my-sheet');

        $refused = [];
        foreach ([['bob', 'my-sheet'], ['ann', 'nothing']] as [$user, $key]) {
            try {
                $this->sheet($user, $key);
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
        yield 'an invalid field' => [static fn (self $t) => $t->save(null, ['key' => 'Not A Key', 'label' => 'Luck', 'type' => 'number']), InvalidReleaseContent::class];
        yield 'a taken key' => [static fn (self $t) => $t->save(null, ['key' => 'hp', 'label' => 'HP', 'type' => 'number']), SheetFieldKeyTaken::class];
        yield 'a missing original key' => [static fn (self $t) => $t->save('nowhere', ['key' => 'luck', 'label' => 'Luck', 'type' => 'number']), SheetFieldNotFound::class];
        yield 'renaming a referenced field' => [static fn (self $t) => $t->save('pow', ['key' => 'power', 'label' => 'Power', 'type' => 'number']), SheetFieldInUse::class];
        yield 'deleting a missing field' => [static fn (self $t) => $t->delete('nowhere'), SheetFieldNotFound::class];
        yield 'deleting a referenced field' => [static fn (self $t) => $t->delete('sanity'), SheetFieldInUse::class];
        yield 'moving a missing field' => [static fn (self $t) => $t->move('nowhere', 0), SheetFieldNotFound::class];
        yield 'moving out of range' => [static fn (self $t) => $t->move('pow', 4), InvalidReleaseContent::class];
        yield 'saving a layout before declaring one' => [static fn (self $t) => $t->saveLayout(1, [['name' => 'General']]), InvalidReleaseContent::class];
        yield 'removing a layout nobody declared' => [static fn (self $t) => $t->removeLayout(), InvalidReleaseContent::class];
        yield 'removing a point budget nobody saved' => [static fn (self $t) => $t->removeBudgetEntry('points'), SheetPointBudgetNotFound::class];
        yield 'a point budget without a total' => [static fn (self $t) => $t->saveBudgetEntry('points', ['label' => 'Points', 'fields' => ['pow']]), InvalidReleaseContent::class];
    }

    /**
     * @param \Closure(self): void     $command
     * @param class-string<\Throwable> $refusal
     */
    #[Test]
    #[DataProvider('refusedCommands')]
    public function aRefusedCommandLeavesTheStoredDraftUntouched(\Closure $command, string $refusal): void
    {
        $draft = $this->draft('my-sheet');
        $updatedAt = $draft->updatedAt();

        $this->expectRefusal($refusal, $draft, fn () => $command($this));

        self::assertEquals($updatedAt, $draft->updatedAt());
    }

    #[Test]
    public function aSchemaVersion1DraftRefusesEverySheetCommand(): void
    {
        $draft = $this->draft('my-journal', 'valid/contract-doc-example');

        $this->expectRefusal(InvalidReleaseContent::class, $draft, fn () => $this->save(null, ['key' => 'luck', 'label' => 'Luck', 'type' => 'number'], key: 'my-journal'));
        $this->expectRefusal(InvalidReleaseContent::class, $draft, fn () => $this->delete('luck', key: 'my-journal'));
        $this->expectRefusal(InvalidReleaseContent::class, $draft, fn () => $this->move('luck', 0, key: 'my-journal'));
        $this->expectRefusal(InvalidReleaseContent::class, $draft, fn () => $this->saveLayout(1, [['name' => 'General']], key: 'my-journal'));
        $this->expectRefusal(InvalidReleaseContent::class, $draft, fn () => $this->declareLayout(key: 'my-journal'));
        $this->expectRefusal(InvalidReleaseContent::class, $draft, fn () => $this->removeLayout(key: 'my-journal'));
        $this->expectRefusal(InvalidReleaseContent::class, $draft, fn () => $this->saveBudgetEntry('points', ['label' => 'Points', 'total' => 100, 'fields' => ['pow']], key: 'my-journal'));
        $this->expectRefusal(InvalidReleaseContent::class, $draft, fn () => $this->removeBudgetEntry('points', key: 'my-journal'));
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
     * @param array<mixed> $field
     */
    public function save(?string $originalKey, array $field, string $user = 'ann', int $version = 1, string $key = 'my-sheet'): void
    {
        (new SaveSheetFieldHandler($this->owned, $this->gameSystems, $this->clock))(new SaveSheetField($user, $key, $version, $originalKey, $field));
    }

    public function delete(string $field, string $user = 'ann', int $version = 1, string $key = 'my-sheet'): void
    {
        (new DeleteSheetFieldHandler($this->owned, $this->gameSystems, $this->clock))(new DeleteSheetField($user, $key, $version, $field));
    }

    public function move(string $field, int $index, string $user = 'ann', int $version = 1, string $key = 'my-sheet'): void
    {
        (new MoveSheetFieldHandler($this->owned, $this->gameSystems, $this->clock))(new MoveSheetField($user, $key, $version, $field, $index));
    }

    /**
     * @param array<mixed> $groups
     */
    public function saveLayout(mixed $columns, array $groups, string $user = 'ann', int $version = 1, string $key = 'my-sheet'): void
    {
        (new SaveSheetLayoutHandler($this->owned, $this->gameSystems, $this->clock))(new SaveSheetLayout($user, $key, $version, $columns, $groups));
    }

    public function declareLayout(string $user = 'ann', int $version = 1, string $key = 'my-sheet'): void
    {
        (new DeclareSheetLayoutHandler($this->owned, $this->gameSystems, $this->clock))(new DeclareSheetLayout($user, $key, $version));
    }

    public function removeLayout(string $user = 'ann', int $version = 1, string $key = 'my-sheet'): void
    {
        (new RemoveSheetLayoutHandler($this->owned, $this->gameSystems, $this->clock))(new RemoveSheetLayout($user, $key, $version));
    }

    /**
     * @param array<array-key, mixed> $budget
     */
    public function saveBudgetEntry(string $budgetKey, array $budget, string $user = 'ann', int $version = 1, string $key = 'my-sheet'): void
    {
        (new SaveSheetPointBudgetEntryHandler($this->owned, $this->gameSystems, $this->clock))(new SaveSheetPointBudgetEntry($user, $key, $version, $budgetKey, $budget));
    }

    public function removeBudgetEntry(string $budgetKey, string $user = 'ann', int $version = 1, string $key = 'my-sheet'): void
    {
        (new RemoveSheetPointBudgetEntryHandler($this->owned, $this->gameSystems, $this->clock))(new RemoveSheetPointBudgetEntry($user, $key, $version, $budgetKey));
    }

    /**
     * @return list<string> the distinct groups its fields name
     */
    private function groupsOf(GameSystemSheetView $view): array
    {
        return array_values(array_unique(array_filter(array_column($view->fields, 'group'), \is_string(...))));
    }

    /**
     * @return array<mixed>|null the point budget stored in the draft's sheet
     */
    private static function storedBudget(GameSystem $draft): ?array
    {
        $sheet = $draft->document()['sheet'] ?? null;
        $budget = \is_array($sheet) ? ($sheet['pointBudget'] ?? null) : null;

        return \is_array($budget) ? $budget : null;
    }

    private function sheet(string $user, string $key): GameSystemSheetView
    {
        return (new GetGameSystemSheetHandler($this->owned, $this->releases))(new GetGameSystemSheet($user, $key));
    }

    /**
     * Publishes a release of the draft's GameSystem whose sheet has number fields with these keys.
     *
     * @param list<string> $fieldKeys
     */
    private function release(GameSystem $draft, int $version, array $fieldKeys): void
    {
        $document = ReleaseArrays::with($draft->document(), 'sheet', ['fields' => array_map(static fn (string $key): array => ['key' => $key, 'label' => $key, 'type' => 'number'], $fieldKeys)]);
        $document = ReleaseArrays::with($document, 'sceneTypes', []);
        $document = ReleaseArrays::with($document, 'checks', []);
        $this->releases->add(GameSystemRelease::publish(ReleaseId::fromString('release-'.$version), ReleaseContent::fromArray($document), $version, $this->clock->now()));
    }

    private function draft(string $key, string $fixture = 'valid/v2-sheet-effects'): GameSystem
    {
        $source = ReleaseContent::fromArray(ReleaseArrays::fixture($fixture))->toArray();
        $draft = GameSystem::createFromRelease(GameSystemId::fromString(\sprintf('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f%04x', 0x100 + ++$this->drafts)), $key, 'Draft', null, 'ann', $source, $this->clock->now());
        $this->gameSystems->add($draft);

        return $draft;
    }
}
