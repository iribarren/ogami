<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\Release;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\Version2\Formulas;
use App\Studio\Domain\Release\Version2\PointBudget;
use App\Studio\Domain\Release\Version2\SheetFields;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Structured character creation (ADR 0040): a dice default on a number field, a start max formula
 * on a track and a point budget on the sheet. All additive, so a sheet without them is unchanged.
 */
#[CoversClass(SheetFields::class)]
#[CoversClass(PointBudget::class)]
#[CoversClass(Formulas::class)]
final class ReleaseContentCreationTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed>       $extra  more sheet properties, such as the point budget
     *
     * @return array<mixed>
     */
    private function release(array $fields, array $extra = [], string $fixture = 'valid/v2-minimal'): array
    {
        return ReleaseArrays::with(ReleaseArrays::fixture($fixture), 'sheet', ['fields' => $fields] + $extra);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fields(): array
    {
        return [
            ['key' => 'str', 'label' => 'Strength', 'type' => 'number', 'min' => 1, 'max' => 99, 'roll' => ['dice' => '3d6*5']],
            ['key' => 'con', 'label' => 'Constitution', 'type' => 'number', 'min' => 1, 'max' => 99, 'roll' => ['dice' => '3d6*5']],
            ['key' => 'half', 'label' => 'Half', 'type' => 'derived', 'formula' => '({str}+{con})/10'],
            ['key' => 'hp', 'label' => 'Hit points', 'type' => 'track', 'startMax' => ['formula' => '{half}']],
        ];
    }

    /**
     * @param array<string, mixed> $budget
     *
     * @return array<string, mixed>
     */
    private static function budget(array $budget = []): array
    {
        return ['pointBudget' => $budget + ['label' => 'Points', 'total' => 460, 'fields' => ['str', 'con'], 'min' => 15, 'max' => 90]];
    }

    /**
     * @return array<string, mixed>
     */
    private function sheetOf(ReleaseContent $content): array
    {
        /** @var array{sheet: array<string, mixed>} $canonical */
        $canonical = $content->toArray();

        return $canonical['sheet'];
    }

    /**
     * @param array<mixed> $release
     */
    private function assertRefused(array $release, string $message): void
    {
        try {
            ReleaseContent::fromArray($release);
            self::fail('Expected InvalidReleaseContent');
        } catch (InvalidReleaseContent $invalid) {
            self::assertStringContainsString($message, $invalid->getMessage());
        }
    }

    #[Test]
    public function aSheetWithTheCreationPropertiesRoundTripsInCanonicalForm(): void
    {
        $content = ReleaseContent::fromArray($this->release($this->fields(), self::budget()));
        /** @var array{sheet: array<string, mixed>} $canonical */
        $canonical = $content->toArray();

        self::assertSame(['fields', 'pointBudget'], array_keys($canonical['sheet']));
        self::assertSame(['label', 'total', 'fields', 'min', 'max'], array_keys((array) $canonical['sheet']['pointBudget']));

        /** @var array<mixed> $decoded */
        $decoded = json_decode(json_encode($canonical, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame($content->hash(), ReleaseContent::fromArray($decoded)->hash());
    }

    #[Test]
    public function nullCreationOptionalsAreLeftOutSoASheetWithoutThemKeepsItsForm(): void
    {
        $plain = [['key' => 'str', 'label' => 'Strength', 'type' => 'number'], ['key' => 'hp', 'label' => 'Hit points', 'type' => 'track', 'max' => 10]];
        $nulls = [['roll' => null] + $plain[0], ['startMax' => null] + $plain[1]];

        $without = ReleaseContent::fromArray($this->release($plain));
        $with = ReleaseContent::fromArray($this->release($nulls, ['pointBudget' => null]));

        self::assertSame($without->hash(), $with->hash());
        self::assertEquals($without->toArray(), $with->toArray());
    }

    #[Test]
    public function aBudgetMayOmitItsLabelAndBounds(): void
    {
        $content = ReleaseContent::fromArray($this->release($this->fields(), ['pointBudget' => ['total' => 300, 'fields' => ['str'], 'min' => null]]));

        self::assertEquals(['total' => 300, 'fields' => ['str']], $this->sheetOf($content)['pointBudget'] ?? null);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusedRolls(): iterable
    {
        yield 'not an object' => [['roll' => '3d6'], 'sheet.fields[0].roll'];
        yield 'bad notation' => [['roll' => ['dice' => '3d6x5']], 'sheet.fields[0].roll.dice: invalid dice notation'];
        yield 'blank' => [['roll' => ['dice' => '']], 'sheet.fields[0].roll.dice'];
        yield 'extra property' => [['roll' => ['dice' => '3d6', 'then' => 1]], 'sheet.fields[0].roll'];
    }

    /**
     * @param array<string, mixed> $change
     */
    #[Test]
    #[DataProvider('refusedRolls')]
    public function aDiceDefaultMustBeAKernelExpression(array $change, string $message): void
    {
        $fields = $this->fields();
        $fields[0] = $change + $fields[0];

        $this->assertRefused($this->release($fields), $message);
    }

    #[Test]
    public function aDiceDefaultIsOnlyForNumberFields(): void
    {
        $fields = $this->fields();
        $fields[] = ['key' => 'role', 'label' => 'Role', 'type' => 'text', 'roll' => ['dice' => '1d6']];

        $this->assertRefused($this->release($fields), 'sheet.fields[4]');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusedStartMax(): iterable
    {
        yield 'with a fixed max' => [['max' => 10], 'sheet.fields[3].startMax: cannot be combined with max'];
        yield 'not an object' => [['startMax' => '{half}'], 'sheet.fields[3].startMax'];
        yield 'bad syntax' => [['startMax' => ['formula' => '{half}+']], 'sheet.fields[3].startMax.formula'];
        yield 'unknown field' => [['startMax' => ['formula' => '{nobody}']], '{nobody}: unknown field "nobody"'];
        yield 'a check value' => [['startMax' => ['formula' => '{check:difficulty}']], '{check:difficulty}: a start max formula cannot read a check value'];
        yield 'a track max' => [['startMax' => ['formula' => '{hp.max}']], '{hp.max}: a start max formula cannot read a track max'];
        yield 'a track' => [['startMax' => ['formula' => '{mp}']], '{mp}: field "mp" is a track field'];
        yield 'a text field' => [['startMax' => ['formula' => '{role}']], '{role}: field "role" is a text field'];
        yield 'a tracker' => [['startMax' => ['formula' => '{tracker:chaos}']], '{tracker:chaos}: a start max formula cannot read a tracker'];
        yield 'a derived field over a track' => [['startMax' => ['formula' => '{rest}']], '{rest}: derived field "rest" reads {mp}'];
        yield 'a derived field over a derived field over a tracker' => [['startMax' => ['formula' => '{outer}']], '{outer}: derived field "outer" reads {inner}'];
    }

    /**
     * @param array<string, mixed> $change
     */
    #[Test]
    #[DataProvider('refusedStartMax')]
    public function aStartMaxFormulaReadsNumberAndCreationOnlyDerivedFields(array $change, string $message): void
    {
        $fields = [
            ...$this->fields(),
            ['key' => 'mp', 'label' => 'Magic points', 'type' => 'track'],
            ['key' => 'role', 'label' => 'Role', 'type' => 'text'],
            ['key' => 'rest', 'label' => 'Rest', 'type' => 'derived', 'formula' => '{mp}*2'],
            ['key' => 'inner', 'label' => 'Inner', 'type' => 'derived', 'formula' => '{tracker:chaos}'],
            ['key' => 'outer', 'label' => 'Outer', 'type' => 'derived', 'formula' => '{inner}+1'],
        ];
        $fields[3] = $change + $fields[3];

        // valid/v2-scene-types declares the tracker "chaos".
        $this->assertRefused($this->release($fields, fixture: 'valid/v2-scene-types'), $message);
    }

    #[Test]
    public function aStartMaxFormulaMayReadADerivedFieldThatReadsNumbersAcrossOtherDerivedFields(): void
    {
        $fields = $this->fields();
        array_splice($fields, 3, 0, [['key' => 'bonus', 'label' => 'Bonus', 'type' => 'derived', 'formula' => '{half}+1']]);
        $fields[4]['startMax'] = ['formula' => '{bonus}+{str}'];

        $content = ReleaseContent::fromArray($this->release($fields));

        /** @var list<array<string, mixed>> $stored */
        $stored = $this->sheetOf($content)['fields'];
        self::assertSame(['formula' => '{bonus}+{str}'], $stored[4]['startMax'] ?? null);
    }

    #[Test]
    public function aDerivedFieldSharedByTwoOthersIsJudgedOnceAndStillRefusedOverATrack(): void
    {
        $diamond = static fn (string $base): array => [
            ['key' => 'str', 'label' => 'Strength', 'type' => 'number'],
            ['key' => 'mp', 'label' => 'Magic points', 'type' => 'track'],
            ['key' => 'shared', 'label' => 'Shared', 'type' => 'derived', 'formula' => $base],
            ['key' => 'left', 'label' => 'Left', 'type' => 'derived', 'formula' => '{shared}+1'],
            ['key' => 'right', 'label' => 'Right', 'type' => 'derived', 'formula' => '{shared}*2'],
            ['key' => 'hp', 'label' => 'Hit points', 'type' => 'track', 'startMax' => ['formula' => '{left}+{right}']],
        ];

        $this->assertRefused($this->release($diamond('{mp}')), '{left}: derived field "left" reads {shared}');

        $content = ReleaseContent::fromArray($this->release($diamond('{str}')));
        /** @var list<array<string, mixed>> $stored */
        $stored = $this->sheetOf($content)['fields'];
        self::assertSame(['formula' => '{left}+{right}'], $stored[5]['startMax'] ?? null);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusedBudgets(): iterable
    {
        yield 'not an object' => [['pointBudget' => 460], 'sheet.pointBudget'];
        yield 'extra property' => [self::budget(['spare' => 1]), 'sheet.pointBudget'];
        yield 'blank label' => [self::budget(['label' => '']), 'sheet.pointBudget.label'];
        yield 'missing total' => [['pointBudget' => ['fields' => ['str']]], 'sheet.pointBudget'];
        yield 'zero total' => [self::budget(['total' => 0]), 'sheet.pointBudget.total'];
        yield 'huge total' => [self::budget(['total' => 1_000_001]), 'sheet.pointBudget.total'];
        yield 'fractional total' => [self::budget(['total' => 4.5]), 'sheet.pointBudget.total'];
        yield 'no fields' => [self::budget(['fields' => []]), 'sheet.pointBudget.fields'];
        yield 'fields not a list' => [self::budget(['fields' => 'str']), 'sheet.pointBudget.fields'];
        yield 'unknown field' => [self::budget(['fields' => ['str', 'luck']]), 'sheet.pointBudget.fields[1]: unknown field "luck"'];
        yield 'a derived field' => [self::budget(['fields' => ['half']]), 'sheet.pointBudget.fields[0]: field "half" is a derived field, a budget covers number fields'];
        yield 'a repeated field' => [self::budget(['fields' => ['str', 'con', 'str']]), 'sheet.pointBudget.fields[2]: duplicate field "str"'];
        yield 'min above max' => [self::budget(['min' => 91]), 'sheet.pointBudget.max: must be at least min (91), 90 given'];
        yield 'bound out of range' => [self::budget(['max' => 1_000_001]), 'sheet.pointBudget.max'];
        yield 'bounds that leave no value' => [self::budget(['min' => 100, 'max' => 120]), 'sheet.pointBudget.fields[0]: "str" allows 1..99, no value fits the budget bounds 100..120'];
        yield 'total below the lowest values' => [self::budget(['total' => 29]), 'sheet.pointBudget.total: 29 is below 30, the sum of the lowest values the budget fields can take'];
    }

    /**
     * @param array<string, mixed> $extra
     */
    #[Test]
    #[DataProvider('refusedBudgets')]
    public function aPointBudgetIsRefusedWhenItCannotBeSpent(array $extra, string $message): void
    {
        $this->assertRefused($this->release($this->fields(), $extra), $message);
    }

    #[Test]
    public function aBudgetTotalMayEqualTheLowestValuesAndUnboundedFieldsCountAsVeryLow(): void
    {
        ReleaseContent::fromArray($this->release($this->fields(), self::budget(['total' => 30])));

        $unbounded = [['key' => 'a', 'label' => 'A', 'type' => 'number'], ['key' => 'b', 'label' => 'B', 'type' => 'number', 'min' => 5]];
        $content = ReleaseContent::fromArray($this->release($unbounded, ['pointBudget' => ['total' => 5, 'fields' => ['a', 'b']]]));

        /** @var array<string, mixed> $budget */
        $budget = $this->sheetOf($content)['pointBudget'];
        self::assertSame(5, $budget['total']);
    }

    #[Test]
    public function aFieldBoundOutsideTheBudgetBoundsStillNeedsOneValueInBoth(): void
    {
        $fields = $this->fields();
        $fields[1]['min'] = 91;

        $this->assertRefused($this->release($fields, self::budget()), 'sheet.pointBudget.fields[1]: "con" allows 91..99, no value fits the budget bounds 15..90');
    }

    #[Test]
    public function aBudgetFieldChangedAwayFromANumberFieldIsRefused(): void
    {
        $fields = [['key' => 'str', 'label' => 'Strength', 'type' => 'text']];

        $this->assertRefused($this->release($fields, self::budget(['fields' => ['str']])), 'field "str" is a text field, a budget covers number fields');
    }

    #[Test]
    public function schemaVersionOneStillRefusesTheseProperties(): void
    {
        $release = ReleaseArrays::with(ReleaseArrays::fixture('valid/minimal'), 'sheet', ['fields' => []] + self::budget());

        $this->assertRefused($release, 'sheet: not supported in schema version 1');
    }
}
