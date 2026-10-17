<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Infrastructure\GameSystem;

use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\Sheet\DerivedField;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\SheetGroup;
use App\Play\Domain\GameSystem\Sheet\SheetLayout;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Play\Domain\GameSystem\Sheet\TextField;
use App\Play\Domain\GameSystem\Sheet\TrackField;
use App\Play\Infrastructure\GameSystem\GameSystemReleaseTranslator;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Play's anti-corruption layer reads the sheet layout (ADR 0031) and fails closed on a layout
 * Studio would have rejected; a release stored before the layout reads as before.
 */
#[CoversClass(GameSystemReleaseTranslator::class)]
#[CoversClass(SheetTemplate::class)]
#[CoversClass(SheetLayout::class)]
#[CoversClass(SheetGroup::class)]
final class GameSystemReleaseTranslatorSheetLayoutTest extends TestCase
{
    /**
     * @param array<string, mixed> $sheet
     */
    private function translate(array $sheet): SheetTemplate
    {
        /** @var array<string, mixed> $content */
        $content = ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-minimal'), 'sheet', $sheet);

        return new GameSystemReleaseTranslator()->translate(ReleaseViews::of($content))->sheet();
    }

    #[Test]
    public function itReadsTheGroupsTheSpansAndTheDefaults(): void
    {
        $sheet = $this->translate([
            'columns' => 2,
            'groups' => [['name' => 'Stats', 'columns' => 3, 'span' => 2], ['name' => 'Notes'], ['name' => 'Empty', 'columns' => null, 'span' => null]],
            'fields' => [
                ['key' => 'str', 'label' => 'Strength', 'type' => 'number', 'group' => 'Stats', 'span' => 3],
                ['key' => 'hp', 'label' => 'Hit points', 'type' => 'track', 'group' => 'Stats'],
                ['key' => 'bonus', 'label' => 'Bonus', 'type' => 'derived', 'formula' => '{str}', 'group' => 'Stats', 'span' => 2],
                ['key' => 'bio', 'label' => 'Bio', 'type' => 'text', 'group' => 'Notes', 'span' => null],
            ],
        ]);

        self::assertEquals(new SheetLayout(2, [new SheetGroup('Stats', 3, 2), new SheetGroup('Notes'), new SheetGroup('Empty')]), $sheet->layout());
        $fields = $sheet->fields();
        self::assertEquals(new NumberField('str', 'Strength', null, 'Stats', span: 3), $fields[0]);
        self::assertEquals(new TrackField('hp', 'Hit points', null, 'Stats'), $fields[1]);
        self::assertInstanceOf(DerivedField::class, $fields[2]);
        self::assertSame(2, $fields[2]->span);
        self::assertEquals(new TextField('bio', 'Bio', null, 'Notes'), $fields[3]);
    }

    #[Test]
    public function theSheetColumnsDefaultToOne(): void
    {
        $sheet = $this->translate(['groups' => [['name' => 'Stats']], 'fields' => [['key' => 'str', 'label' => 'Strength', 'type' => 'number', 'group' => 'Stats']]]);

        self::assertEquals(new SheetLayout(1, [new SheetGroup('Stats')]), $sheet->layout());
    }

    #[Test]
    public function aReleaseStoredWithoutLayoutReadsAsBefore(): void
    {
        $content = ReleaseViews::fixtureContent('valid/v2-sheet');
        $sheet = new GameSystemReleaseTranslator()->translate(ReleaseViews::of([...$content, 'sceneTypes' => []]))->sheet();

        self::assertNull($sheet->layout());
        self::assertSame([1], array_values(array_unique(array_map(static fn (\App\Play\Domain\GameSystem\Sheet\Field $field): int => $field->span, $sheet->fields()))));
        self::assertNull(new SheetTemplate()->layout());
        self::assertNull($this->translate(['fields' => [], 'groups' => [], 'columns' => null])->layout());
        self::assertNull($this->translate(['groups' => null])->layout());
    }

    #[Test]
    public function theLimitsAreInclusive(): void
    {
        $groups = array_map(static fn (int $i): array => ['name' => 'G'.$i], range(2, 20));
        $sheet = $this->translate([
            'columns' => 2,
            'groups' => [['name' => str_repeat('n', 60), 'columns' => 4, 'span' => 2], ...$groups],
            'fields' => [['key' => 'str', 'label' => 'Strength', 'type' => 'number', 'group' => str_repeat('n', 60), 'span' => 4]],
        ]);

        self::assertCount(20, $sheet->layout()->groups ?? []);
        self::assertSame(4, $sheet->fields()[0]->span);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}> a sheet, the expected path and reason
     */
    public static function badLayouts(): iterable
    {
        $stats = [['name' => 'Stats']];
        $field = static fn (array $extra = []): array => ['key' => 'str', 'label' => 'Strength', 'type' => 'number', 'group' => 'Stats', ...$extra];

        yield 'columns without groups' => [['columns' => 2], 'sheet.columns: needs declared groups.'];
        yield 'columns with empty groups' => [['columns' => 2, 'groups' => []], 'sheet.columns: needs declared groups.'];
        yield 'columns 0' => [['columns' => 0, 'groups' => $stats], 'sheet.columns: must be between 1 and 2.'];
        yield 'columns 3' => [['columns' => 3, 'groups' => $stats], 'sheet.columns: must be between 1 and 2.'];
        yield 'columns string' => [['columns' => '2', 'groups' => $stats], 'sheet.columns: must be an integer.'];
        yield 'unknown sheet property' => [['layout' => []], 'sheet.layout: unknown property.'];
        yield 'groups object' => [['groups' => ['name' => 'Stats']], 'sheet.groups: must be a list.'];
        yield 'twenty-one groups' => [['groups' => array_map(static fn (int $i): array => ['name' => 'G'.$i], range(1, 21))], 'sheet.groups: at most 20 groups.'];
        yield 'group not an object' => [['groups' => ['Stats']], 'sheet.groups[0]: must be an object.'];
        yield 'group without name' => [['groups' => [['columns' => 2]]], 'sheet.groups[0].name: must be a string.'];
        yield 'group name blank' => [['groups' => [['name' => ' ']]], 'sheet.groups[0].name: must not be blank.'];
        yield 'group name 61 characters' => [['groups' => [['name' => str_repeat('n', 61)]]], 'sheet.groups[0].name: must be at most 60 characters.'];
        yield 'duplicate group name' => [['groups' => [...$stats, ...$stats]], 'sheet.groups[1].name: duplicate group name "Stats".'];
        yield 'group columns 0' => [['groups' => [['name' => 'Stats', 'columns' => 0]]], 'sheet.groups[0].columns: must be between 1 and 4.'];
        yield 'group columns 5' => [['groups' => [['name' => 'Stats', 'columns' => 5]]], 'sheet.groups[0].columns: must be between 1 and 4.'];
        yield 'group columns string' => [['groups' => [['name' => 'Stats', 'columns' => '2']]], 'sheet.groups[0].columns: must be an integer.'];
        yield 'group span 0' => [['groups' => [['name' => 'Stats', 'span' => 0]]], 'sheet.groups[0].span: must be between 1 and 2.'];
        yield 'group span 3' => [['groups' => [['name' => 'Stats', 'span' => 3]]], 'sheet.groups[0].span: must be between 1 and 2.'];
        yield 'group unknown property' => [['groups' => [['name' => 'Stats', 'color' => 'red']]], 'sheet.groups[0].color: unknown property.'];
        yield 'group span above the sheet columns' => [['groups' => [...$stats, ['name' => 'Notes', 'span' => 2]]], 'sheet.groups[1].span: must be at most the columns of the sheet (1).'];
        yield 'field span without groups' => [['fields' => [['key' => 'str', 'label' => 'Strength', 'type' => 'number', 'span' => 1]]], 'sheet.fields[0].span: needs declared groups.'];
        yield 'field span 0' => [['groups' => $stats, 'fields' => [$field(['span' => 0])]], 'sheet.fields[0].span: must be between 1 and 4.'];
        yield 'field span 5' => [['groups' => $stats, 'fields' => [$field(['span' => 5])]], 'sheet.fields[0].span: must be between 1 and 4.'];
        yield 'field span string' => [['groups' => $stats, 'fields' => [$field(['span' => '1'])]], 'sheet.fields[0].span: must be an integer.'];
        yield 'field span above its group columns' => [['groups' => [['name' => 'Stats', 'columns' => 2]], 'fields' => [$field(['span' => 3])]], 'sheet.fields[0].span: must be at most the columns of group "Stats" (2).'];
        yield 'field span above the default group columns' => [['groups' => $stats, 'fields' => [$field(['span' => 2])]], 'sheet.fields[0].span: must be at most the columns of group "Stats" (1).'];
        yield 'field without group' => [['groups' => $stats, 'fields' => [$field(['group' => null])]], 'sheet.fields[0].group: needs one of the declared groups.'];
        yield 'field in an undeclared group' => [['groups' => $stats, 'fields' => [$field(['group' => 'stats'])]], 'sheet.fields[0].group: unknown group "stats".'];
    }

    /**
     * @param array<string, mixed> $sheet
     */
    #[Test]
    #[DataProvider('badLayouts')]
    public function itFailsClosedOnALayoutStudioWouldHaveRejected(array $sheet, string $reason): void
    {
        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains('cannot be read by Play: '.$reason);

        $this->translate($sheet);
    }
}
