<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Infrastructure\GameSystem;

use App\Formula\Domain\Formula;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\PointBudget;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Play\Domain\GameSystem\Sheet\TrackField;
use App\Play\Infrastructure\GameSystem\GameSystemReleaseTranslator;
use App\Randomness\Domain\DiceExpression;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Play's anti-corruption layer reads the structured character creation properties (ADR 0040): a
 * dice default, a start max formula and a point budget. It refuses the shapes it cannot represent;
 * the cross-field rules are Studio's, checked on publish.
 */
#[CoversClass(GameSystemReleaseTranslator::class)]
#[CoversClass(SheetTemplate::class)]
#[CoversClass(NumberField::class)]
#[CoversClass(TrackField::class)]
#[CoversClass(PointBudget::class)]
final class GameSystemReleaseTranslatorCreationTest extends TestCase
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

    /**
     * @return list<array<string, mixed>>
     */
    private static function fields(): array
    {
        return [
            ['key' => 'str', 'label' => 'Strength', 'type' => 'number', 'min' => 1, 'max' => 99, 'roll' => ['dice' => '3d6*5']],
            ['key' => 'con', 'label' => 'Constitution', 'type' => 'number', 'min' => 1, 'max' => 99, 'default' => 50, 'roll' => ['dice' => '(2d6+6)*5']],
            ['key' => 'hp', 'label' => 'Hit points', 'type' => 'track', 'startMax' => ['formula' => '({str}+{con})/10']],
        ];
    }

    #[Test]
    public function itReadsTheDiceDefaultsTheStartMaxAndThePointBudget(): void
    {
        $sheet = $this->translate([
            'fields' => self::fields(),
            'pointBudget' => ['label' => 'Points', 'total' => 460, 'fields' => ['str', 'con'], 'min' => 15, 'max' => 90],
        ]);

        self::assertEquals(
            [
                new NumberField('str', 'Strength', null, null, 1, 99, null, 1, DiceExpression::fromString('3d6*5')),
                new NumberField('con', 'Constitution', null, null, 1, 99, 50, 1, DiceExpression::fromString('(2d6+6)*5')),
                new TrackField('hp', 'Hit points', startMax: Formula::fromString('({str}+{con})/10')),
            ],
            $sheet->fields(),
        );
        self::assertEquals([new PointBudget(460, ['str', 'con'], 'Points', 15, 90)], $sheet->budgets());
    }

    #[Test]
    public function aBudgetNeedsOnlyATotalAndFields(): void
    {
        $budget = $this->translate(['fields' => self::fields(), 'pointBudget' => ['total' => 10, 'fields' => ['str'], 'label' => null, 'min' => null]])->budgets();

        self::assertEquals([new PointBudget(10, ['str'])], $budget);
    }

    #[Test]
    public function aSheetWithoutTheCreationPropertiesReadsAsBefore(): void
    {
        $sheet = $this->translate(['fields' => [['key' => 'str', 'label' => 'Strength', 'type' => 'number'], ['key' => 'hp', 'label' => 'Hit points', 'type' => 'track', 'max' => 10]]]);

        self::assertSame([], $sheet->budgets());
        self::assertEquals([new NumberField('str', 'Strength'), new TrackField('hp', 'Hit points', max: 10)], $sheet->fields());
        self::assertSame([], $this->translate(['fields' => [], 'pointBudget' => null])->budgets());
        self::assertSame([], $this->translate(['fields' => [], 'pointBudgets' => []])->budgets());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}> a sheet, the expected path and reason
     */
    public static function malformed(): iterable
    {
        $with = static function (int $index, array $change): array {
            $fields = self::fields();
            $fields[$index] = $change + $fields[$index];

            return ['fields' => $fields];
        };
        $budget = static fn (array $change): array => ['fields' => self::fields(), 'pointBudget' => $change + ['total' => 460, 'fields' => ['str', 'con']]];

        yield 'roll not an object' => [$with(0, ['roll' => '3d6']), 'sheet.fields[0].roll: must be an object.'];
        yield 'roll without dice' => [$with(0, ['roll' => []]), 'sheet.fields[0].roll.dice: must be a string.'];
        yield 'roll dice not a string' => [$with(0, ['roll' => ['dice' => 3]]), 'sheet.fields[0].roll.dice: must be a string.'];
        yield 'roll bad notation' => [$with(0, ['roll' => ['dice' => '3d6x5']]), 'sheet.fields[0].roll.dice: invalid dice notation'];
        yield 'roll too long' => [$with(0, ['roll' => ['dice' => '1d6'.str_repeat('+1', 50)]]), 'sheet.fields[0].roll.dice: must be at most 100 characters.'];
        yield 'roll unknown property' => [$with(0, ['roll' => ['dice' => '3d6', 'then' => 1]]), 'sheet.fields[0].roll.then: unknown property.'];
        yield 'roll on a track' => [$with(2, ['roll' => ['dice' => '1d6']]), 'sheet.fields[2].roll: unknown property.'];
        yield 'start max with a max' => [$with(2, ['max' => 10]), 'sheet.fields[2].startMax: cannot be combined with max.'];
        yield 'start max not an object' => [$with(2, ['startMax' => '{str}']), 'sheet.fields[2].startMax: must be an object.'];
        yield 'start max without formula' => [$with(2, ['startMax' => []]), 'sheet.fields[2].startMax.formula: must be a string.'];
        yield 'start max bad formula' => [$with(2, ['startMax' => ['formula' => '{str}+']]), 'sheet.fields[2].startMax.formula'];
        yield 'start max too long' => [$with(2, ['startMax' => ['formula' => str_repeat('1+', 100).'1']]), 'sheet.fields[2].startMax.formula: must be at most 200 characters.'];
        yield 'start max unknown property' => [$with(2, ['startMax' => ['formula' => '1', 'then' => 1]]), 'sheet.fields[2].startMax.then: unknown property.'];
        yield 'start max on a number' => [$with(0, ['startMax' => ['formula' => '1']]), 'sheet.fields[0].startMax: unknown property.'];
        yield 'budget not an object' => [['fields' => self::fields(), 'pointBudget' => 460], 'sheet.pointBudget: must be an object.'];
        yield 'budget unknown property' => [$budget(['spare' => 1]), 'sheet.pointBudget.spare: unknown property.'];
        yield 'budget without total' => [['fields' => self::fields(), 'pointBudget' => ['fields' => ['str']]], 'sheet.pointBudget.total: required.'];
        yield 'budget total a string' => [$budget(['total' => '460']), 'sheet.pointBudget.total: must be an integer.'];
        yield 'budget total 0' => [$budget(['total' => 0]), 'sheet.pointBudget.total: must be between 1 and 1000000.'];
        yield 'budget label blank' => [$budget(['label' => ' ']), 'sheet.pointBudget.label: must not be blank.'];
        yield 'budget label 61 characters' => [$budget(['label' => str_repeat('n', 61)]), 'sheet.pointBudget.label: must be at most 60 characters.'];
        yield 'budget without fields' => [['fields' => self::fields(), 'pointBudget' => ['total' => 10]], 'sheet.pointBudget.fields: must be a list.'];
        yield 'budget fields empty' => [$budget(['fields' => []]), 'sheet.pointBudget.fields: must list between 1 and 100 fields.'];
        yield 'budget field not a key' => [$budget(['fields' => ['Str']]), 'sheet.pointBudget.fields[0]'];
        yield 'budget field unknown' => [$budget(['fields' => ['luck']]), 'sheet.pointBudget: The point budget covers "luck", which is not a number field.'];
        yield 'budget field a track' => [$budget(['fields' => ['hp']]), 'sheet.pointBudget: The point budget covers "hp", which is not a number field.'];
        yield 'budget field repeated' => [$budget(['fields' => ['str', 'str']]), 'sheet.pointBudget: A point budget covers at least one field, each once.'];
        yield 'budget min a string' => [$budget(['min' => '1']), 'sheet.pointBudget.min: must be an integer.'];
        yield 'budget min above max' => [$budget(['min' => 91, 'max' => 90]), 'sheet.pointBudget.max: must be at least min (91).'];
    }

    /**
     * @param array<string, mixed> $sheet
     */
    #[Test]
    #[DataProvider('malformed')]
    public function itFailsClosedOnAShapeItCannotRepresent(array $sheet, string $reason): void
    {
        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains('cannot be read by Play: '.$reason);

        $this->translate($sheet);
    }
}
