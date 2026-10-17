<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\Release;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\Version2\SheetLayout;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The sheet layout of schema version 2 (ADR 0031): a group grid, additive, so a release without
 * layout keeps its canonical form and hash. Every rule is an item rule: it also runs on a draft.
 */
#[CoversClass(ReleaseContent::class)]
#[CoversClass(SheetLayout::class)]
final class ReleaseContentSheetLayoutTest extends TestCase
{
    /** sha256 of the canonical JSON of valid/v2-sheet, computed before the layout existed. */
    private const string SHEET_HASH = '80e866bbc910b09ca4b4b6ae2d6d8348e776e5ca8573c300f84a46bbf4d9a1c6';

    #[Test]
    public function aReleaseWithoutLayoutKeepsItsCanonicalFormAndHash(): void
    {
        $content = ReleaseContent::fromArray(ReleaseArrays::fixture('valid/v2-sheet'));

        self::assertSame(self::SHEET_HASH, $content->hash());
        self::assertStringNotContainsString('"groups"', json_encode($content->toArray(), \JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function aLayoutRoundTripsInCanonicalForm(): void
    {
        $content = ReleaseContent::fromArray($this->release(
            [['name' => 'Stats', 'columns' => 3, 'span' => 2], ['name' => 'Notes', 'columns' => 2]],
            [$this->field('str', 'Stats', 3), $this->field('bio', 'Notes', 2)],
            2,
        ));

        self::assertEquals([
            'fields' => [
                ['key' => 'str', 'label' => 'Label', 'group' => 'Stats', 'span' => 3, 'type' => 'number'],
                ['key' => 'bio', 'label' => 'Label', 'group' => 'Notes', 'span' => 2, 'type' => 'number'],
            ],
            'columns' => 2,
            'groups' => [['name' => 'Stats', 'columns' => 3, 'span' => 2], ['name' => 'Notes', 'columns' => 2]],
        ], $content->toArray()['sheet']);
        self::assertSame(['fields', 'columns', 'groups'], array_keys((array) $content->toArray()['sheet']));

        /** @var array<mixed> $decoded */
        $decoded = json_decode(json_encode($content->toArray(), \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame($content->hash(), ReleaseContent::fromArray($decoded)->hash());
    }

    #[Test]
    public function defaultsAndEmptyOrNullPartsAreLeftOutOfTheCanonicalForm(): void
    {
        $content = ReleaseContent::fromArray($this->release(
            [['name' => 'Stats', 'columns' => 1, 'span' => 1], ['name' => 'Notes', 'columns' => null, 'span' => null]],
            [$this->field('str', 'Stats', 1)],
            1,
        ));

        self::assertEquals([
            'fields' => [['key' => 'str', 'label' => 'Label', 'group' => 'Stats', 'type' => 'number']],
            'groups' => [['name' => 'Stats'], ['name' => 'Notes']],
        ], $content->toArray()['sheet']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function absentLayouts(): iterable
    {
        yield 'groups null' => [['groups' => null, 'columns' => null]];
        yield 'groups empty' => [['groups' => []]];
        yield 'neither' => [[]];
    }

    /**
     * @param array<string, mixed> $layout
     */
    #[Test]
    #[DataProvider('absentLayouts')]
    public function emptyOrNullGroupsAreTheSameAsNoLayout(array $layout): void
    {
        $sheet = ReleaseArrays::fixture('valid/v2-sheet')['sheet'];
        self::assertIsArray($sheet);
        $plain = ReleaseContent::fromArray(ReleaseArrays::fixture('valid/v2-sheet'));
        $content = ReleaseContent::fromArray(ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-sheet'), 'sheet', [...$sheet, ...$layout]));

        self::assertSame($plain->hash(), $content->hash());
    }

    #[Test]
    public function anEmptyDeclaredGroupIsValid(): void
    {
        $content = ReleaseContent::fromArray($this->release([['name' => 'Stats'], ['name' => 'Empty']], [$this->field('str', 'Stats')]));

        self::assertEquals([['name' => 'Stats'], ['name' => 'Empty']], $this->sheetOf($content)['groups'] ?? null);
    }

    #[Test]
    public function theLimitsAreInclusive(): void
    {
        $twenty = array_map(static fn (int $i): array => ['name' => 'G'.$i], range(1, 20));
        $content = ReleaseContent::fromArray($this->release(
            [['name' => str_repeat('n', 60), 'columns' => 4, 'span' => 2], ...\array_slice($twenty, 1)],
            [$this->field('str', str_repeat('n', 60), 4)],
            2,
        ));

        $groups = $this->sheetOf($content)['groups'] ?? [];
        self::assertIsArray($groups);
        self::assertCount(20, $groups);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}> a sheet, the expected message prefix
     */
    public static function invalidLayouts(): iterable
    {
        $stats = [['name' => 'Stats']];
        $field = static fn (array $extra = []): array => ['key' => 'str', 'label' => 'Label', 'type' => 'number', 'group' => 'Stats', ...$extra];
        $one = static fn (array $groups, array $fields = [], mixed $columns = null): array => ['groups' => $groups, 'fields' => $fields] + (null === $columns ? [] : ['columns' => $columns]);

        yield 'columns without groups' => [['columns' => 2], 'sheet.columns: needs declared groups'];
        yield 'columns 1 without groups' => [['columns' => 1], 'sheet.columns: needs declared groups'];
        yield 'columns with empty groups' => [['columns' => 2, 'groups' => []], 'sheet.columns: needs declared groups'];
        yield 'columns 0' => [$one($stats, [], 0), 'sheet.columns: must be at least 1, 0 given'];
        yield 'columns 3' => [$one($stats, [], 3), 'sheet.columns: must be at most 2, 3 given'];
        yield 'columns string' => [$one($stats, [], '2'), 'sheet.columns: must be an integer'];
        yield 'groups object' => [['groups' => ['name' => 'Stats']], 'sheet.groups: must be a list'];
        yield 'groups string' => [['groups' => 'Stats'], 'sheet.groups: must be a list'];
        yield 'twenty-one groups' => [['groups' => array_map(static fn (int $i): array => ['name' => 'G'.$i], range(1, 21))], 'sheet.groups: at most 20 groups, 21 given'];
        yield 'group not an object' => [['groups' => ['Stats']], 'sheet.groups[0]: must be an object'];
        yield 'group without name' => [['groups' => [['columns' => 2]]], 'sheet.groups[0].name: required'];
        yield 'group name empty' => [['groups' => [['name' => '']]], 'sheet.groups[0].name: must not be empty'];
        yield 'group name blank' => [['groups' => [['name' => '  ']]], 'sheet.groups[0].name: must not be blank'];
        yield 'group name 61 characters' => [['groups' => [['name' => str_repeat('n', 61)]]], 'sheet.groups[0].name: must be at most 60 characters, 61 given'];
        yield 'group name not a string' => [['groups' => [['name' => 3]]], 'sheet.groups[0].name: must be a string'];
        yield 'duplicate group name' => [['groups' => [['name' => 'Stats'], ['name' => 'Stats']]], 'sheet.groups[1].name: duplicate group name "Stats"'];
        yield 'group columns 0' => [['groups' => [['name' => 'Stats', 'columns' => 0]]], 'sheet.groups[0].columns: must be at least 1, 0 given'];
        yield 'group columns 5' => [['groups' => [['name' => 'Stats', 'columns' => 5]]], 'sheet.groups[0].columns: must be at most 4, 5 given'];
        yield 'group columns string' => [['groups' => [['name' => 'Stats', 'columns' => '2']]], 'sheet.groups[0].columns: must be an integer'];
        yield 'group span 0' => [['groups' => [['name' => 'Stats', 'span' => 0]]], 'sheet.groups[0].span: must be at least 1, 0 given'];
        yield 'group span 3' => [['groups' => [['name' => 'Stats', 'span' => 3]]], 'sheet.groups[0].span: must be at most 2, 3 given'];
        yield 'group span string' => [['groups' => [['name' => 'Stats', 'span' => 'all']]], 'sheet.groups[0].span: must be an integer'];
        yield 'group unknown property' => [['groups' => [['name' => 'Stats', 'color' => 'red']]], 'sheet.groups[0].color: unknown property'];
        yield 'group span above the sheet columns' => [['groups' => [['name' => 'Stats'], ['name' => 'Notes', 'span' => 2]]], 'sheet.groups[1].span: must be at most the columns of the sheet (1), 2 given'];
        yield 'group span above explicit sheet columns' => [['columns' => 1, 'groups' => [['name' => 'Stats', 'span' => 2]]], 'sheet.groups[0].span: must be at most the columns of the sheet (1), 2 given'];
        yield 'field span without groups' => [['fields' => [['key' => 'str', 'label' => 'Label', 'type' => 'number', 'span' => 1]]], 'sheet.fields[0].span: needs declared groups'];
        yield 'field span 0' => [$one($stats, [$field(['span' => 0])]), 'sheet.fields[0].span: must be at least 1, 0 given'];
        yield 'field span 5' => [$one($stats, [$field(['span' => 5])]), 'sheet.fields[0].span: must be at most 4, 5 given'];
        yield 'field span string' => [$one($stats, [$field(['span' => '2'])]), 'sheet.fields[0].span: must be an integer'];
        yield 'field span above its group columns' => [$one([['name' => 'Stats', 'columns' => 2]], [$field(['span' => 3])]), 'sheet.fields[0].span: must be at most the columns of group "Stats" (2), 3 given'];
        yield 'field span above the default group columns' => [$one($stats, [$field(['span' => 2])]), 'sheet.fields[0].span: must be at most the columns of group "Stats" (1), 2 given'];
        yield 'derived field span above its group columns' => [$one($stats, [['key' => 'bonus', 'label' => 'Bonus', 'type' => 'derived', 'formula' => '1', 'group' => 'Stats', 'span' => 2]]), 'sheet.fields[0].span: must be at most'];
        yield 'field without group' => [$one($stats, [['key' => 'str', 'label' => 'Label', 'type' => 'number']]), 'sheet.fields[0].group: required, the sheet declares groups'];
        yield 'field in an undeclared group' => [$one($stats, [$field(['group' => 'Notes'])]), 'sheet.fields[0].group: unknown group "Notes"'];
        yield 'group names match exactly' => [$one($stats, [$field(['group' => 'stats'])]), 'sheet.fields[0].group: unknown group "stats"'];
        yield 'the second field is the one reported' => [$one($stats, [$field(), $field(['key' => 'dex', 'group' => 'Gone'])]), 'sheet.fields[1].group: unknown group "Gone"'];
        yield 'unknown sheet property' => [['layout' => []], 'sheet.layout: unknown property'];
    }

    /**
     * @param array<string, mixed> $sheet
     * @param non-empty-string     $message
     */
    #[Test]
    #[DataProvider('invalidLayouts')]
    public function everyInvalidLayoutFailsWithItsPath(array $sheet, string $message): void
    {
        $release = ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-minimal'), 'sheet', $sheet);

        try {
            ReleaseContent::fromArray($release);
            self::fail('The layout should be rejected.');
        } catch (InvalidReleaseContent $error) {
            self::assertStringStartsWith($message, $error->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $sheet
     */
    #[Test]
    #[DataProvider('invalidLayouts')]
    public function theItemRulesRejectTheSameLayouts(array $sheet, string $message): void
    {
        $release = ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-minimal'), 'sheet', $sheet);

        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains($message);

        ReleaseContent::checkItemRules($release);
    }

    /**
     * @return array<string, mixed>
     */
    private function sheetOf(ReleaseContent $content): array
    {
        /** @var array<string, mixed> $sheet */
        $sheet = (array) $content->toArray()['sheet'];

        return $sheet;
    }

    /**
     * @param list<array<string, mixed>> $groups
     * @param list<array<string, mixed>> $fields
     *
     * @return array<mixed>
     */
    private function release(array $groups, array $fields, ?int $columns = null): array
    {
        return ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-minimal'), 'sheet', ['fields' => $fields, 'groups' => $groups, 'columns' => $columns]);
    }

    /**
     * @return array<string, mixed>
     */
    private function field(string $key, string $group, int $span = 1): array
    {
        return ['key' => $key, 'label' => 'Label', 'type' => 'number', 'group' => $group, 'span' => $span];
    }
}
