<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\Characters;

use App\Formula\Domain\Formula;
use App\Play\Domain\Campaign\Characters\CharacterCreation;
use App\Play\Domain\Campaign\Characters\CreationMethod;
use App\Play\Domain\Campaign\Characters\CreationRefusal;
use App\Play\Domain\Campaign\Characters\InvalidCharacter;
use App\Play\Domain\GameSystem\Sheet\DerivedField;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\PointBudget;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Play\Domain\GameSystem\Sheet\TextField;
use App\Play\Domain\GameSystem\Sheet\TrackField;
use App\Randomness\Domain\DiceExpression;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Structured character creation (ADR 0040): the server rolls dice defaults, the player may spend a
 * point budget instead, and a track with a start max starts full once the other values are in.
 */
#[CoversClass(CharacterCreation::class)]
#[CoversClass(CreationMethod::class)]
#[CoversClass(InvalidCharacter::class)]
#[CoversClass(PointBudget::class)]
final class CharacterCreationTest extends TestCase
{
    /**
     * Two budget fields with a dice default, one rolled field outside the budget and a track
     * whose max is a tenth of the two budget fields.
     */
    private function sheet(?PointBudget $budget = null, int $strMax = 99): SheetTemplate
    {
        return new SheetTemplate([
            new NumberField('name-of-the-game', 'Notes', default: 3),
            new NumberField('str', 'Strength', min: 1, max: $strMax, roll: DiceExpression::fromString('3d6*5')),
            new NumberField('con', 'Constitution', min: 1, max: 99, roll: DiceExpression::fromString('(2d6+6)*5')),
            new NumberField('luck', 'Luck', min: 1, max: 99, roll: DiceExpression::fromString('3d6*5')),
            new DerivedField('half', 'Half', null, null, formula: Formula::fromString('({str}+{con})/2')),
            new TrackField('hp', 'Hit points', startMax: Formula::fromString('{half}')),
            new TextField('role', 'Role'),
        ], budget: $budget ?? new PointBudget(460, ['str', 'con'], 'Points', 15, 90));
    }

    #[Test]
    public function byRollTheServerRollsEveryDiceDefaultAndStartsTheTrackFull(): void
    {
        // str 3d6 = 4+5+6 -> 75; con 2d6 = 3+4 -> (7+6)*5 = 65; luck 3d6 = 1+1+2 -> 20; half = 70.
        $values = CharacterCreation::values($this->sheet(), [], CreationMethod::Roll, new ScriptedRandomNumberGenerator(4, 5, 6, 3, 4, 1, 1, 2));

        self::assertSame(
            ['name-of-the-game' => 3, 'str' => 75, 'con' => 65, 'luck' => 20, 'hp' => ['current' => 70, 'max' => 70], 'role' => ''],
            $values,
        );
    }

    #[Test]
    public function aRolledValueIsClampedToTheFieldsOwnBounds(): void
    {
        // 6+6+6 = 18 -> 90, above the 80 the field allows.
        $values = CharacterCreation::values($this->sheet(strMax: 80), [], CreationMethod::Roll, new ScriptedRandomNumberGenerator(6, 6, 6, 1, 1, 1, 1, 1));

        self::assertSame(80, $values['str']);
    }

    #[Test]
    public function byPointsThePlayerSpendsTheBudgetAndTheServerRollsTheRest(): void
    {
        // Only luck, outside the budget, is rolled: 3+3+3 -> 45. half = (60+70)/2 = 65.
        $values = CharacterCreation::values($this->sheet(), ['str' => 60, 'con' => 70, 'role' => 'Spy'], CreationMethod::Points, new ScriptedRandomNumberGenerator(3, 3, 3));

        self::assertSame(['name-of-the-game' => 3, 'str' => 60, 'con' => 70, 'luck' => 45, 'hp' => ['current' => 65, 'max' => 65], 'role' => 'Spy'], $values);
    }

    #[Test]
    public function aSheetThatOffersBothMethodsNeedsOne(): void
    {
        $this->assertRefused(CreationRefusal::MethodRequired, fn (): array => CharacterCreation::values($this->sheet(), [], null, new ScriptedRandomNumberGenerator()));
    }

    #[Test]
    public function pointsNeedABudget(): void
    {
        $sheet = new SheetTemplate([new NumberField('str', 'Strength', roll: DiceExpression::fromString('1d6'))]);

        $this->assertRefused(CreationRefusal::MethodUnavailable, static fn (): array => CharacterCreation::values($sheet, [], CreationMethod::Points, new ScriptedRandomNumberGenerator()));
        // Rolling is what the server does anyway: asking for it is harmless.
        self::assertSame(['str' => 4], CharacterCreation::values($sheet, [], CreationMethod::Roll, new ScriptedRandomNumberGenerator(4)));
        self::assertSame(['str' => 2], CharacterCreation::values($sheet, [], null, new ScriptedRandomNumberGenerator(2)));
    }

    #[Test]
    public function aBudgetWithoutDiceDefaultsIsSpentWithoutChoosingAMethod(): void
    {
        $sheet = new SheetTemplate([new NumberField('str', 'Strength', min: 1, max: 10), new NumberField('con', 'Constitution', min: 1, max: 10)], budget: new PointBudget(12, ['str', 'con']));
        $random = new ScriptedRandomNumberGenerator();

        self::assertSame(['str' => 7, 'con' => 5], CharacterCreation::values($sheet, ['str' => 7, 'con' => 5], null, $random));
        self::assertSame(['str' => 7, 'con' => 5], CharacterCreation::values($sheet, ['str' => 7, 'con' => 5], CreationMethod::Points, $random));
        $this->assertRefused(CreationRefusal::MethodUnavailable, static fn (): array => CharacterCreation::values($sheet, [], CreationMethod::Roll, $random));
    }

    #[Test]
    public function aValueForAFieldTheServerSetsIsRefused(): void
    {
        $random = static fn (): ScriptedRandomNumberGenerator => new ScriptedRandomNumberGenerator(1, 1, 1, 1, 1, 1, 1, 1);

        // A rolled field, a rolled field outside the budget, and the start max track.
        foreach (['str', 'luck', 'hp'] as $key) {
            $value = 'hp' === $key ? ['current' => 5, 'max' => 5] : 50;
            $this->assertRefused(CreationRefusal::ValueSetAtCreation, fn (): array => CharacterCreation::values($this->sheet(), [$key => $value], CreationMethod::Roll, $random()));
        }

        $this->assertRefused(CreationRefusal::ValueSetAtCreation, fn (): array => CharacterCreation::values($this->sheet(), ['str' => 50, 'con' => 50, 'luck' => 50], CreationMethod::Points, $random()));
        $this->assertRefused(CreationRefusal::ValueSetAtCreation, fn (): array => CharacterCreation::values($this->sheet(), ['str' => 50, 'con' => 50, 'hp' => ['current' => 1, 'max' => 1]], CreationMethod::Points, $random()));
    }

    #[Test]
    public function everyBudgetValueMustFitTheFieldAndTheBudgetBounds(): void
    {
        $random = static fn (): ScriptedRandomNumberGenerator => new ScriptedRandomNumberGenerator(1, 1, 1);

        // 10 is inside the field (1..99) and outside the budget (15..90), and so is 91.
        foreach ([['str' => 10, 'con' => 50], ['str' => 91, 'con' => 50], ['str' => 50, 'con' => 14]] as $given) {
            $this->assertRefused(CreationRefusal::OutsideBounds, fn (): array => CharacterCreation::values($this->sheet(), $given, CreationMethod::Points, $random()));
        }

        $this->assertRefused(CreationRefusal::OutsideBounds, fn (): array => CharacterCreation::values($this->sheet(strMax: 40), ['str' => 41, 'con' => 50], CreationMethod::Points, $random()), 'The field\'s own max');
        // A budget field without a value is not read as its default.
        $this->assertRefused(CreationRefusal::OutsideBounds, fn (): array => CharacterCreation::values($this->sheet(), ['str' => 50], CreationMethod::Points, $random()));
    }

    #[Test]
    public function aValueOfAnotherTypeIsLeftToTheFieldCheck(): void
    {
        $this->expectException(InvalidCharacter::class);
        $this->expectExceptionMessageIsOrContains('Field "str" must be an integer.');

        CharacterCreation::values($this->sheet(), ['str' => '50', 'con' => 50], CreationMethod::Points, new ScriptedRandomNumberGenerator(1, 1, 1));
    }

    #[Test]
    public function theBudgetTotalIsAnUpperBound(): void
    {
        $sheet = $this->sheet(new PointBudget(100, ['str', 'con']));
        $random = static fn (): ScriptedRandomNumberGenerator => new ScriptedRandomNumberGenerator(1, 1, 1);

        self::assertSame(50, CharacterCreation::values($sheet, ['str' => 50, 'con' => 50], CreationMethod::Points, $random())['str']);
        $this->assertRefused(CreationRefusal::BudgetExceeded, static fn (): array => CharacterCreation::values($sheet, ['str' => 50, 'con' => 51], CreationMethod::Points, $random()));
    }

    /**
     * Strength is rolled; Sanity is a budget field with no dice default, so the player sets it.
     */
    private function mixedSheet(): SheetTemplate
    {
        return new SheetTemplate([
            new NumberField('str', 'Strength', min: 1, max: 99, roll: DiceExpression::fromString('3d6*5')),
            new NumberField('san', 'Sanity', min: 1, max: 99, default: 50),
        ], budget: new PointBudget(100, ['str', 'san'], 'Points', 15, 90));
    }

    #[Test]
    public function byRollThePlayerSetsTheNonDiceBudgetFieldsInsideTheBudgetBoundsWithoutATotal(): void
    {
        $random = static fn (): ScriptedRandomNumberGenerator => new ScriptedRandomNumberGenerator(4, 5, 6);

        // 90 on its own is over the total of 100 once Strength is counted, and that is fine when rolling.
        self::assertSame(['str' => 75, 'san' => 90], CharacterCreation::values($this->mixedSheet(), ['san' => 90], CreationMethod::Roll, $random()));
        self::assertSame(15, CharacterCreation::values($this->mixedSheet(), ['san' => 15], CreationMethod::Roll, $random())['san']);

        // 10 and 91 sit inside the field (1..99) and outside the budget (15..90); a missing value is not read as its default.
        foreach ([['san' => 10], ['san' => 91], []] as $given) {
            $this->assertRefused(CreationRefusal::OutsideBounds, fn (): array => CharacterCreation::values($this->mixedSheet(), $given, CreationMethod::Roll, $random()));
        }

        // A value for the rolled budget field is still refused.
        $this->assertRefused(CreationRefusal::ValueSetAtCreation, fn (): array => CharacterCreation::values($this->mixedSheet(), ['str' => 50, 'san' => 50], CreationMethod::Roll, $random()));
    }

    #[Test]
    public function aBudgetWithNothingToRollIsCheckedWithoutChoosingAMethod(): void
    {
        $sheet = new SheetTemplate([new NumberField('str', 'Strength', min: 1, max: 10), new NumberField('con', 'Constitution', min: 1, max: 10)], budget: new PointBudget(12, ['str', 'con']));

        $this->assertRefused(CreationRefusal::BudgetExceeded, static fn (): array => CharacterCreation::values($sheet, ['str' => 7, 'con' => 6], null, null));
    }

    #[Test]
    public function aPromotionRollsEveryDiceDefaultWhateverTheBudget(): void
    {
        $values = CharacterCreation::promoted($this->sheet(), new ScriptedRandomNumberGenerator(4, 5, 6, 3, 4, 1, 1, 2));

        self::assertSame(['str' => 75, 'con' => 65, 'luck' => 20, 'hp' => ['current' => 70, 'max' => 70]], array_intersect_key($values, array_flip(['str', 'con', 'luck', 'hp'])));
    }

    #[Test]
    public function aStartMaxStaysInsideTheTrackLimits(): void
    {
        $sheet = new SheetTemplate([new NumberField('big', 'Big', default: 5000), new NumberField('debt', 'Debt', default: -3), new TrackField('up', 'Up', startMax: Formula::fromString('{big}')), new TrackField('down', 'Down', startMax: Formula::fromString('{debt}'))]);

        self::assertSame(['current' => 999, 'max' => 999], CharacterCreation::values($sheet, [], null, null)['up']);
        self::assertSame(['current' => 0, 'max' => 0], CharacterCreation::values($sheet, [], null, null)['down']);
    }

    #[Test]
    public function aStartMaxThatCannotBeComputedRefusesTheCharacter(): void
    {
        $sheet = new SheetTemplate([new NumberField('zero', 'Zero'), new TrackField('hp', 'Hit points', startMax: Formula::fromString('10/{zero}'))]);

        $this->expectException(InvalidCharacter::class);
        $this->expectExceptionMessageIsOrContains('The start max of field "hp" cannot be computed');

        CharacterCreation::values($sheet, [], null, null);
    }

    #[Test]
    public function aSheetWithoutTheCreationPropertiesCreatesAsBefore(): void
    {
        $sheet = new SheetTemplate([new NumberField('str', 'Strength', min: 1, max: 10, default: 4), new TrackField('hp', 'Hit points', max: 12), new TrackField('mp', 'Magic points')]);

        self::assertSame(['str' => 7, 'hp' => ['current' => 12, 'max' => 12], 'mp' => ['current' => 0, 'max' => 0]], CharacterCreation::values($sheet, ['str' => 7], null, null));
        self::assertSame(['str' => 4, 'hp' => ['current' => 12, 'max' => 12], 'mp' => ['current' => 0, 'max' => 0]], CharacterCreation::promoted($sheet, null));
    }

    #[Test]
    public function rollingWithoutARandomSourceIsAProgrammingError(): void
    {
        $this->expectException(\LogicException::class);

        CharacterCreation::values($this->sheet(), [], CreationMethod::Roll, null);
    }

    /**
     * @param \Closure(): array<string, mixed> $create
     */
    private function assertRefused(CreationRefusal $refusal, \Closure $create, string $message = ''): void
    {
        try {
            $create();
            self::fail('The Character was accepted. '.$message);
        } catch (InvalidCharacter $invalid) {
            self::assertSame($refusal, $invalid->refusal, $invalid->getMessage());
        }
    }
}
