<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\GameSystem;

use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\GameSystemId;
use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The layout commands of the sheet. Runs against valid/v2-sheet-effects (no layout: fields pow, hp,
 * sanity and bonus). laidOut() declares Stats (2 columns) with pow and bonus, and Health with hp and
 * sanity, on a sheet of 2 columns; pow spans 2.
 */
#[CoversClass(GameSystem::class)]
final class GameSystemSheetLayoutTest extends TestCase
{
    private \DateTimeImmutable $created;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->created = new \DateTimeImmutable('2026-10-15T10:00:00');
        $this->now = new \DateTimeImmutable('2026-10-16T10:00:00');
    }

    private function draft(string $fixture = 'valid/v2-sheet-effects'): GameSystem
    {
        $source = ReleaseContent::fromArray(ReleaseArrays::fixture($fixture))->toArray();

        return GameSystem::createFromRelease(GameSystemId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a72'), 'my-copy', 'My copy', null, 'user-1', $source, $this->created);
    }

    /**
     * @param list<array<string, mixed>>|null $groups  null keeps the default Stats and Health
     * @param array<string, string>|null      $groupOf the group of each field by key; null keeps the default
     */
    private function laidOut(?array $groups = null, ?array $groupOf = null, int $columns = 2): GameSystem
    {
        $gameSystem = $this->draft();
        $groupOf ??= ['pow' => 'Stats', 'hp' => 'Health', 'sanity' => 'Health', 'bonus' => 'Stats'];
        $fields = array_map(static function (array $field) use ($groupOf): array {
            /** @var string $key */
            $key = $field['key'];

            return [...$field, 'group' => $groupOf[$key], ...'pow' === $key ? ['span' => 2] : []];
        }, $this->fields($gameSystem));
        $sheet = [...1 === $columns ? [] : ['columns' => $columns], 'groups' => $groups ?? [['name' => 'Stats', 'columns' => 2], ['name' => 'Health']], 'fields' => $fields];
        $gameSystem->restore(ReleaseArrays::with($gameSystem->document(), 'sheet', $sheet), $this->created);

        return $gameSystem;
    }

    /**
     * @return array<string, mixed>
     */
    private function sheet(GameSystem $gameSystem): array
    {
        /** @var array<string, mixed> $sheet */
        $sheet = $gameSystem->document()['sheet'];

        return $sheet;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fields(GameSystem $gameSystem): array
    {
        /** @var list<array<string, mixed>> $fields */
        $fields = $this->sheet($gameSystem)['fields'] ?? [];

        return $fields;
    }

    /**
     * @return array<string, string> the group of each field by key
     */
    private function groupOfEach(GameSystem $gameSystem): array
    {
        $groups = [];
        foreach ($this->fields($gameSystem) as $field) {
            /** @var string $key */
            $key = $field['key'];
            /** @var string $group */
            $group = $field['group'] ?? '';
            $groups[$key] = $group;
        }

        return $groups;
    }

    /**
     * @return list<string>
     */
    private function keys(GameSystem $gameSystem): array
    {
        /** @var list<string> $keys */
        $keys = array_column($this->fields($gameSystem), 'key');

        return $keys;
    }

    /**
     * @param \Closure(): void $command
     * @param non-empty-string $messageStart
     */
    private function assertRefused(GameSystem $gameSystem, \Closure $command, string $messageStart): void
    {
        $document = $gameSystem->document();
        $version = $gameSystem->version();
        $updatedAt = $gameSystem->updatedAt();

        try {
            $command();
            self::fail('Expected InvalidReleaseContent');
        } catch (InvalidReleaseContent $invalid) {
            self::assertStringStartsWith($messageStart, $invalid->getMessage());
        }

        self::assertSame($document, $gameSystem->document());
        self::assertSame($version, $gameSystem->version());
        self::assertEquals($updatedAt, $gameSystem->updatedAt());
    }

    #[Test]
    public function aRenameCascadesToTheFieldsAndKeepsTheirOrderAndSpans(): void
    {
        $gameSystem = $this->laidOut();
        $before = $this->fields($gameSystem);

        $gameSystem->saveSheetLayout(2, [['name' => 'Attributes', 'previousName' => 'Stats', 'columns' => 2], ['name' => 'Health']], $this->now);

        $sheet = $this->sheet($gameSystem);
        self::assertSame([['name' => 'Attributes', 'columns' => 2], ['name' => 'Health']], $sheet['groups']);
        self::assertSame(['pow' => 'Attributes', 'hp' => 'Health', 'sanity' => 'Health', 'bonus' => 'Attributes'], $this->groupOfEach($gameSystem));
        self::assertSame(['pow', 'hp', 'sanity', 'bonus'], $this->keys($gameSystem));
        self::assertSame(2, $this->fields($gameSystem)[0]['span']);
        self::assertSame($before[1], $this->fields($gameSystem)[1], 'A field of an untouched group is as it was.');
        self::assertEquals($this->now, $gameSystem->updatedAt());
        ReleaseContent::checkItemRules($gameSystem->document());
    }

    #[Test]
    public function twoGroupsCanSwapNamesInOneSave(): void
    {
        $gameSystem = $this->laidOut();

        $gameSystem->saveSheetLayout(2, [['name' => 'Health', 'previousName' => 'Stats', 'columns' => 2], ['name' => 'Stats', 'previousName' => 'Health']], $this->now);

        self::assertSame(['pow' => 'Health', 'hp' => 'Stats', 'sanity' => 'Stats', 'bonus' => 'Health'], $this->groupOfEach($gameSystem));
        self::assertSame([['name' => 'Health', 'columns' => 2], ['name' => 'Stats']], $this->sheet($gameSystem)['groups']);
    }

    #[Test]
    public function aGroupWithoutPreviousNameKeepsItsIdentityByName(): void
    {
        $gameSystem = $this->laidOut();

        $gameSystem->saveSheetLayout(2, [['name' => 'Health', 'columns' => 3, 'span' => 2], ['name' => 'Stats', 'columns' => 2]], $this->now);

        self::assertSame([['name' => 'Health', 'columns' => 3, 'span' => 2], ['name' => 'Stats', 'columns' => 2]], $this->sheet($gameSystem)['groups']);
        self::assertSame(['pow' => 'Stats', 'hp' => 'Health', 'sanity' => 'Health', 'bonus' => 'Stats'], $this->groupOfEach($gameSystem));
    }

    #[Test]
    public function defaultsAreLeftOutAndPreviousNameIsNeverStored(): void
    {
        $gameSystem = $this->laidOut();

        $gameSystem->saveSheetLayout(1, [['name' => 'Stats', 'previousName' => 'Stats', 'columns' => 2, 'span' => 1], ['name' => 'Health', 'columns' => null, 'span' => null]], $this->now);

        // pow spans 2 columns of Stats, which has 2: still fine on a sheet of one column.
        $sheet = $this->sheet($gameSystem);
        self::assertArrayNotHasKey('columns', $sheet);
        self::assertSame([['name' => 'Stats', 'columns' => 2], ['name' => 'Health']], $sheet['groups']);
    }

    #[Test]
    public function aMissingColumnsLeavesTheSheetAtOneColumn(): void
    {
        $gameSystem = $this->laidOut();

        $gameSystem->saveSheetLayout(null, [['name' => 'Stats', 'columns' => 2], ['name' => 'Health']], $this->now);

        self::assertArrayNotHasKey('columns', $this->sheet($gameSystem));
    }

    #[Test]
    public function groupsAreReorderedAddedAndEmptyOnesDropped(): void
    {
        $gameSystem = $this->laidOut([['name' => 'Stats', 'columns' => 2], ['name' => 'Health'], ['name' => 'Gear']]);

        $gameSystem->saveSheetLayout(2, [['name' => 'Notes'], ['name' => 'Health'], ['name' => 'Stats', 'columns' => 2]], $this->now);

        self::assertSame([['name' => 'Notes'], ['name' => 'Health'], ['name' => 'Stats', 'columns' => 2]], $this->sheet($gameSystem)['groups']);
        self::assertSame(['pow' => 'Stats', 'hp' => 'Health', 'sanity' => 'Health', 'bonus' => 'Stats'], $this->groupOfEach($gameSystem));
    }

    #[Test]
    public function droppingAGroupThatHoldsFieldsIsRefusedAndNothingChanges(): void
    {
        $gameSystem = $this->laidOut();

        $this->assertRefused($gameSystem, fn () => $gameSystem->saveSheetLayout(2, [['name' => 'Stats', 'columns' => 2]], $this->now), 'groups: cannot drop the group "Health", it holds 2 fields, move or delete its fields first');
    }

    #[Test]
    public function aRenameDoesNotCountAsADrop(): void
    {
        $gameSystem = $this->laidOut();

        $gameSystem->saveSheetLayout(2, [['name' => 'Body', 'previousName' => 'Health'], ['name' => 'Stats', 'columns' => 2]], $this->now);

        self::assertSame('Body', $this->groupOfEach($gameSystem)['hp']);
    }

    #[Test]
    public function aSheetWithoutDeclaredGroupsMustDeclareThemFirst(): void
    {
        $gameSystem = $this->draft();

        $this->assertRefused($gameSystem, fn () => $gameSystem->saveSheetLayout(1, [['name' => 'Stats']], $this->now), 'sheet.groups: no layout is declared, declare the layout first');
    }

    #[Test]
    public function anEmptyGroupListPointsToTheDelete(): void
    {
        $gameSystem = $this->laidOut();

        $this->assertRefused($gameSystem, fn () => $gameSystem->saveSheetLayout(2, [], $this->now), 'groups: must not be empty');
    }

    /**
     * @return iterable<string, array{mixed, non-empty-string}>
     */
    public static function badIdentities(): iterable
    {
        yield 'a previous name nobody has' => ['Ghost', 'groups[1].previousName: no declared group is named "Ghost"'];
        yield 'a previous name that is not a string' => [7, 'groups[1].previousName: must be the name of a declared group'];
        yield 'a previous name that is a list' => [['Stats'], 'groups[1].previousName: must be the name of a declared group'];
    }

    /**
     * @param non-empty-string $message
     */
    #[Test]
    #[DataProvider('badIdentities')]
    public function aPreviousNameThatIsNoDeclaredGroupIsRefused(mixed $previousName, string $message): void
    {
        $gameSystem = $this->laidOut();

        $this->assertRefused($gameSystem, fn () => $gameSystem->saveSheetLayout(2, [['name' => 'Stats', 'columns' => 2], ['name' => 'Health', 'previousName' => $previousName]], $this->now), $message);
    }

    #[Test]
    public function twoGroupsThatResolveToTheSameCurrentGroupAreRefused(): void
    {
        $gameSystem = $this->laidOut();

        $this->assertRefused($gameSystem, fn () => $gameSystem->saveSheetLayout(2, [['name' => 'Stats', 'columns' => 2], ['name' => 'Body', 'previousName' => 'Stats'], ['name' => 'Health']], $this->now), 'groups[1]: names the group "Stats" like groups[0]');
        $this->assertRefused($gameSystem, fn () => $gameSystem->saveSheetLayout(2, [['name' => 'Body', 'previousName' => 'Health'], ['name' => 'Health'], ['name' => 'Stats']], $this->now), 'groups[1]: names the group "Health" like groups[0]');
    }

    /**
     * @return iterable<string, array{mixed, list<mixed>, non-empty-string}>
     */
    public static function malformedLayouts(): iterable
    {
        $stats = ['name' => 'Stats', 'columns' => 2];
        $health = ['name' => 'Health'];

        yield 'columns a string' => ['two', [$stats, $health], 'sheet.columns'];
        yield 'columns above 2' => [3, [$stats, $health], 'sheet.columns'];
        yield 'columns 0' => [0, [$stats, $health], 'sheet.columns'];
        yield 'columns a float' => [1.5, [$stats, $health], 'sheet.columns'];
        yield 'columns a list' => [[2], [$stats, $health], 'sheet.columns'];
        yield 'a group that is not an object' => [2, ['Stats'], 'groups[0]'];
        yield 'a group that is a non-empty list' => [2, [['Stats']], 'groups[0]'];
        yield 'a group without a name' => [2, [$stats, $health, ['columns' => 2]], 'groups[2].name'];
        yield 'a name that is a number' => [2, [$stats, $health, ['name' => 7]], 'groups[2].name'];
        yield 'a blank name' => [2, [$stats, $health, ['name' => '']], 'sheet.groups[2].name'];
        yield 'a name over 60 characters' => [2, [$stats, $health, ['name' => str_repeat('a', 61)]], 'sheet.groups[2].name'];
        yield 'a duplicate name' => [2, [$stats, $health, ['name' => 'Stats']], 'groups[2]'];
        yield 'two new groups with one name' => [2, [$stats, $health, ['name' => 'Gear'], ['name' => 'Gear']], 'sheet.groups[3].name'];
        yield 'group columns 5' => [2, [['name' => 'Stats', 'columns' => 5], $health], 'sheet.groups[0].columns'];
        yield 'group columns a string' => [2, [['name' => 'Stats', 'columns' => '2'], $health], 'sheet.groups[0].columns'];
        yield 'a span above the sheet columns' => [1, [['name' => 'Stats', 'columns' => 2, 'span' => 2], $health], 'sheet.groups[0].span'];
        yield 'a span 3' => [2, [['name' => 'Stats', 'columns' => 2, 'span' => 3], $health], 'sheet.groups[0].span'];
        yield 'an unknown property' => [2, [['name' => 'Stats', 'columns' => 2, 'colour' => 'red'], $health], 'sheet.groups[0]'];
        yield 'fewer columns than a field spans' => [2, [['name' => 'Stats'], $health], 'sheet.fields[0].span'];
        yield 'more than 20 groups' => [2, [$stats, $health, ...array_map(static fn (int $n): array => ['name' => 'G'.$n], range(1, 19))], 'sheet.groups'];
    }

    /**
     * @param list<mixed>      $groups
     * @param non-empty-string $path
     */
    #[Test]
    #[DataProvider('malformedLayouts')]
    public function malformedValuesAreRefusedWithAPathAndNothingChanges(mixed $columns, array $groups, string $path): void
    {
        $gameSystem = $this->laidOut();

        $this->assertRefused($gameSystem, fn () => $gameSystem->saveSheetLayout($columns, $groups, $this->now), $path);
    }

    #[Test]
    public function aLegacyDraftBreakingTheItemRulesElsewhereIsRefusedWithThatPathByEveryLayoutCommand(): void
    {
        $gameSystem = $this->laidOut();
        $gameSystem->restore(ReleaseArrays::with($gameSystem->document(), 'sceneTypes.0.oracles', ['ghost']), $this->created);

        $this->assertRefused($gameSystem, fn () => $gameSystem->saveSheetLayout(2, [['name' => 'Stats', 'columns' => 2], ['name' => 'Health']], $this->now), 'sceneTypes[0].oracles[0]: ');
        $this->assertRefused($gameSystem, fn () => $gameSystem->removeSheetLayout($this->now), 'sceneTypes[0].oracles[0]: ');

        $legacy = $this->draft();
        $legacy->restore(ReleaseArrays::with($legacy->document(), 'sceneTypes.0.oracles', ['ghost']), $this->created);
        $this->assertRefused($legacy, fn () => $legacy->declareSheetLayout($this->now), 'sceneTypes[0].oracles[0]: ');
    }

    #[Test]
    public function aDraftStoredBeforeTheLayoutExistedDeclaresItAndRemovesItAgain(): void
    {
        $gameSystem = $this->draft();
        self::assertArrayNotHasKey('groups', $this->sheet($gameSystem));
        $before = $this->fields($gameSystem);

        $gameSystem->declareSheetLayout($this->now);
        $gameSystem->removeSheetLayout($this->now);

        self::assertSame([...array_map(static fn (array $field): array => [...$field, 'group' => 'General'], $before)], $this->fields($gameSystem));
    }

    #[Test]
    public function declaringGroupsFollowsFirstAppearanceAndAFieldWithoutAGroupIsGeneral(): void
    {
        $gameSystem = $this->draft();
        $gameSystem->restore(ReleaseArrays::with($gameSystem->document(), 'sheet.fields', array_map(
            static fn (array $field, ?string $group): array => [...$field, ...null === $group ? [] : ['group' => $group]],
            $this->fields($gameSystem),
            ['Vitals', null, 'Vitals', 'Extras'],
        )), $this->created);

        $gameSystem->declareSheetLayout($this->now);

        $sheet = $this->sheet($gameSystem);
        self::assertSame([['name' => 'Vitals'], ['name' => 'General'], ['name' => 'Extras']], $sheet['groups']);
        self::assertArrayNotHasKey('columns', $sheet);
        self::assertSame(['pow', 'sanity', 'hp', 'bonus'], $this->keys($gameSystem), 'Each group is contiguous, relative order kept.');
        self::assertSame(['pow' => 'Vitals', 'sanity' => 'Vitals', 'hp' => 'General', 'bonus' => 'Extras'], $this->groupOfEach($gameSystem));
        self::assertEquals($this->now, $gameSystem->updatedAt());
        ReleaseContent::checkItemRules($gameSystem->document());
    }

    #[Test]
    public function aNumericGroupNameStaysATextWhenDeclared(): void
    {
        $gameSystem = $this->draft();
        $gameSystem->restore(ReleaseArrays::with($gameSystem->document(), 'sheet.fields', array_map(
            static fn (array $field, string $group): array => [...$field, 'group' => $group],
            $this->fields($gameSystem),
            ['7', 'Other', '7', 'Other'],
        )), $this->created);

        $gameSystem->declareSheetLayout($this->now);

        self::assertSame([['name' => '7'], ['name' => 'Other']], $this->sheet($gameSystem)['groups']);
        self::assertSame(['pow' => '7', 'sanity' => '7', 'hp' => 'Other', 'bonus' => 'Other'], $this->groupOfEach($gameSystem));
        ReleaseContent::checkItemRules($gameSystem->document());
    }

    #[Test]
    public function aFieldWhoseGroupIsLiterallyGeneralSharesTheGroupOfTheFieldsWithoutOne(): void
    {
        $gameSystem = $this->draft();
        $gameSystem->restore(ReleaseArrays::with($gameSystem->document(), 'sheet.fields', array_map(
            static fn (array $field, ?string $group): array => [...$field, ...null === $group ? [] : ['group' => $group]],
            $this->fields($gameSystem),
            ['General', 'Other', null, 'General'],
        )), $this->created);

        $gameSystem->declareSheetLayout($this->now);

        self::assertSame([['name' => 'General'], ['name' => 'Other']], $this->sheet($gameSystem)['groups']);
        self::assertSame(['pow', 'sanity', 'bonus', 'hp'], $this->keys($gameSystem));
    }

    #[Test]
    public function declaringOnASheetThatAlreadyHasGroupsIsRefused(): void
    {
        $gameSystem = $this->laidOut();

        $this->assertRefused($gameSystem, fn () => $gameSystem->declareSheetLayout($this->now), 'sheet.groups: already declared');
    }

    #[Test]
    public function declaringOnASheetWithoutFieldsIsRefused(): void
    {
        foreach ([null, ['fields' => []]] as $sheet) {
            $gameSystem = $this->draft('valid/v2-minimal');
            $document = $gameSystem->document();
            if (null === $sheet) {
                unset($document['sheet']);
            } else {
                $document['sheet'] = $sheet;
            }
            $gameSystem->restore($document, $this->created);

            $this->assertRefused($gameSystem, fn () => $gameSystem->declareSheetLayout($this->now), 'sheet.fields: add a field first');
        }
    }

    #[Test]
    public function declaringMoreThanTwentyGroupsFailsThroughTheItemRules(): void
    {
        $gameSystem = $this->draft('valid/v2-minimal');
        $fields = array_map(static fn (int $n): array => ['key' => 'f'.$n, 'label' => 'F', 'group' => 'G'.$n, 'type' => 'number'], range(1, 21));
        $gameSystem->restore(ReleaseArrays::with($gameSystem->document(), 'sheet', ['fields' => $fields]), $this->created);

        $this->assertRefused($gameSystem, fn () => $gameSystem->declareSheetLayout($this->now), 'sheet.groups');
    }

    #[Test]
    public function aFieldWithAGroupThatIsNoTextCannotBeDeclared(): void
    {
        $gameSystem = $this->draft();
        $gameSystem->restore(ReleaseArrays::with($gameSystem->document(), 'sheet.fields.1.group', 7), $this->created);

        $this->assertRefused($gameSystem, fn () => $gameSystem->declareSheetLayout($this->now), 'sheet.fields[1].group: must be a text');
    }

    #[Test]
    public function removingTheLayoutDropsColumnsGroupsAndSpansAndKeepsGroupsAndOrder(): void
    {
        $gameSystem = $this->laidOut();

        $gameSystem->removeSheetLayout($this->now);

        $sheet = $this->sheet($gameSystem);
        self::assertSame(['fields'], array_keys($sheet));
        self::assertSame(['pow' => 'Stats', 'hp' => 'Health', 'sanity' => 'Health', 'bonus' => 'Stats'], $this->groupOfEach($gameSystem));
        self::assertSame(['pow', 'hp', 'sanity', 'bonus'], $this->keys($gameSystem));
        foreach ($this->fields($gameSystem) as $field) {
            self::assertArrayNotHasKey('span', $field);
        }
        self::assertEquals($this->now, $gameSystem->updatedAt());
        ReleaseContent::checkItemRules($gameSystem->document());
    }

    #[Test]
    public function removingOnASheetWithoutGroupsIsRefused(): void
    {
        $gameSystem = $this->draft();

        $this->assertRefused($gameSystem, fn () => $gameSystem->removeSheetLayout($this->now), 'sheet.groups: no declared layout');
    }

    #[Test]
    public function aSchemaVersion1DraftHasNoLayoutCommands(): void
    {
        $gameSystem = $this->draft('valid/contract-doc-example');
        $message = 'schemaVersion: the sheet needs schema version 2, this draft is version 1';

        $this->assertRefused($gameSystem, fn () => $gameSystem->saveSheetLayout(1, [['name' => 'Stats']], $this->now), $message);
        $this->assertRefused($gameSystem, fn () => $gameSystem->declareSheetLayout($this->now), $message);
        $this->assertRefused($gameSystem, fn () => $gameSystem->removeSheetLayout($this->now), $message);
    }

    #[Test]
    public function aSheetThatIsNotWellFormedIsRefusedAtItsPath(): void
    {
        $gameSystem = $this->draft();
        $gameSystem->restore(ReleaseArrays::with($gameSystem->document(), 'sheet', ['groups' => 'many', 'fields' => []]), $this->created);

        $this->assertRefused($gameSystem, fn () => $gameSystem->declareSheetLayout($this->now), 'sheet.groups: must be a list');
        $this->assertRefused($gameSystem, fn () => $gameSystem->removeSheetLayout($this->now), 'sheet.groups: must be a list');
        $this->assertRefused($gameSystem, fn () => $gameSystem->saveSheetLayout(1, [['name' => 'A']], $this->now), 'sheet.groups: must be a list');
    }
}
