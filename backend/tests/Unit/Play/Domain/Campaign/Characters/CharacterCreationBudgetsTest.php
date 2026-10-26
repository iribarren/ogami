<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\Characters;

use App\Play\Domain\Campaign\Characters\CharacterCreation;
use App\Play\Domain\Campaign\Characters\CreationMethod;
use App\Play\Domain\Campaign\Characters\CreationRefusal;
use App\Play\Domain\Campaign\Characters\InvalidCharacter;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\PointBudget;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Randomness\Domain\DiceExpression;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Several point budgets (ADR 0043): a budget is rollable when it covers a field with a dice
 * default; by roll the rollable budgets are skipped and the others spent, by points all are spent.
 */
#[CoversClass(CharacterCreation::class)]
#[CoversClass(SheetTemplate::class)]
#[CoversClass(PointBudget::class)]
final class CharacterCreationBudgetsTest extends TestCase
{
    /**
     * Strength and Constitution are rolled or bought (100 points, 15 to 90); Lore and Sneak are
     * skills bought with 5 points, at most 3 each, never rolled.
     */
    private function sheet(): SheetTemplate
    {
        return new SheetTemplate([
            new NumberField('str', 'Strength', min: 1, max: 99, roll: DiceExpression::fromString('3d6*5')),
            new NumberField('con', 'Constitution', min: 1, max: 99, roll: DiceExpression::fromString('3d6*5')),
            new NumberField('lore', 'Lore', min: 0, max: 5),
            new NumberField('sneak', 'Sneak', min: 0, max: 5),
            new NumberField('luck', 'Luck', min: 1, max: 99, roll: DiceExpression::fromString('3d6*5')),
        ], budgets: [
            new PointBudget(100, ['str', 'con'], 'Attribute points', 15, 90, 'attributes'),
            new PointBudget(5, ['lore', 'sneak'], 'Skill points', null, 3, 'skills'),
        ]);
    }

    #[Test]
    public function byRollTheRollableBudgetIsSkippedAndTheOtherIsSpent(): void
    {
        // str 4+5+6 -> 75, con 1+1+2 -> 20, luck 3+3+3 -> 45.
        $values = CharacterCreation::values($this->sheet(), ['lore' => 3, 'sneak' => 2], CreationMethod::Roll, new ScriptedRandomNumberGenerator(4, 5, 6, 1, 1, 2, 3, 3, 3));

        self::assertSame(['str' => 75, 'con' => 20, 'lore' => 3, 'sneak' => 2, 'luck' => 45], $values);
    }

    #[Test]
    public function byPointsEveryBudgetIsSpentAndOnlyFieldsOutsideThemAreRolled(): void
    {
        $values = CharacterCreation::values($this->sheet(), ['str' => 50, 'con' => 50, 'lore' => 3, 'sneak' => 2], CreationMethod::Points, new ScriptedRandomNumberGenerator(3, 3, 3));

        self::assertSame(['str' => 50, 'con' => 50, 'lore' => 3, 'sneak' => 2, 'luck' => 45], $values);
    }

    #[Test]
    public function aMethodIsRequiredWhenSomeBudgetIsRollable(): void
    {
        $this->assertRefused(CreationRefusal::MethodRequired, fn (): array => CharacterCreation::values($this->sheet(), ['lore' => 1, 'sneak' => 1], null, new ScriptedRandomNumberGenerator()));
    }

    #[Test]
    public function withNoRollableBudgetNoMethodIsNeededAndRollIsUnavailable(): void
    {
        $sheet = new SheetTemplate([new NumberField('lore', 'Lore', min: 0, max: 5), new NumberField('sneak', 'Sneak', min: 0, max: 5), new NumberField('luck', 'Luck', min: 0, max: 5)], budgets: [
            new PointBudget(4, ['lore'], 'Lore points', key: 'lore'),
            new PointBudget(4, ['sneak'], 'Sneak points', key: 'sneak'),
        ]);

        self::assertSame(['lore' => 2, 'sneak' => 3, 'luck' => 0], CharacterCreation::values($sheet, ['lore' => 2, 'sneak' => 3], null, null));
        self::assertSame(['lore' => 2, 'sneak' => 3, 'luck' => 0], CharacterCreation::values($sheet, ['lore' => 2, 'sneak' => 3], CreationMethod::Points, null));
        $this->assertRefused(CreationRefusal::MethodUnavailable, static fn (): array => CharacterCreation::values($sheet, ['lore' => 2, 'sneak' => 3], CreationMethod::Roll, null));
    }

    /**
     * @return iterable<string, array{array<string, int>, CreationMethod, CreationRefusal, string}>
     */
    public static function refusals(): iterable
    {
        yield 'a value for a rolled attribute by roll' => [['str' => 50, 'lore' => 1, 'sneak' => 1], CreationMethod::Roll, CreationRefusal::ValueSetAtCreation, 'Field "str" is set when the Character is created'];
        yield 'over the skill budget' => [['lore' => 3, 'sneak' => 3], CreationMethod::Roll, CreationRefusal::BudgetExceeded, '"Skill points" allows 5 points, 6 spent.'];
        yield 'a skill above the budget max' => [['lore' => 4, 'sneak' => 1], CreationMethod::Roll, CreationRefusal::OutsideBounds, 'Field "lore" of "Skill points" must be 0 to 3 when spending points, got 4.'];
        yield 'a skill missing' => [['lore' => 1], CreationMethod::Roll, CreationRefusal::OutsideBounds, 'Field "sneak" is part of "Skill points": give it a value.'];
        yield 'over the attribute budget' => [['str' => 60, 'con' => 50, 'lore' => 1, 'sneak' => 1], CreationMethod::Points, CreationRefusal::BudgetExceeded, '"Attribute points" allows 100 points, 110 spent.'];
        yield 'an attribute below the budget min' => [['str' => 10, 'con' => 50, 'lore' => 1, 'sneak' => 1], CreationMethod::Points, CreationRefusal::OutsideBounds, 'Field "str" of "Attribute points" must be 15 to 90 when spending points, got 10.'];
        yield 'an attribute missing' => [['str' => 50, 'lore' => 1, 'sneak' => 1], CreationMethod::Points, CreationRefusal::OutsideBounds, 'Field "con" is part of "Attribute points": give it a value.'];
        yield 'a skill over its budget by points' => [['str' => 50, 'con' => 50, 'lore' => 3, 'sneak' => 3], CreationMethod::Points, CreationRefusal::BudgetExceeded, '"Skill points" allows 5 points, 6 spent.'];
    }

    /**
     * @param array<string, int> $given
     */
    #[Test]
    #[DataProvider('refusals')]
    public function aBrokenBudgetIsRefusedByItsLabelWithAnUnchangedCode(array $given, CreationMethod $method, CreationRefusal $refusal, string $message): void
    {
        try {
            CharacterCreation::values($this->sheet(), $given, $method, new ScriptedRandomNumberGenerator(1, 1, 1, 1, 1, 1, 1, 1, 1));
            self::fail('The Character was accepted.');
        } catch (InvalidCharacter $invalid) {
            self::assertSame($refusal, $invalid->refusal);
            self::assertStringContainsString($message, $invalid->getMessage());
        }
    }

    #[Test]
    public function aRollableBudgetKeepsItsBoundsForTheNonDiceFieldsUnderRoll(): void
    {
        $sheet = new SheetTemplate([
            new NumberField('str', 'Strength', min: 1, max: 99, roll: DiceExpression::fromString('3d6*5')),
            new NumberField('san', 'Sanity', min: 1, max: 99, default: 50),
            new NumberField('lore', 'Lore', min: 0, max: 5),
        ], budgets: [new PointBudget(100, ['str', 'san'], 'Attribute points', 15, 90, 'attributes'), new PointBudget(4, ['lore'], 'Skill points', key: 'skills')]);
        $random = static fn (): ScriptedRandomNumberGenerator => new ScriptedRandomNumberGenerator(4, 5, 6);

        // Sanity 90 with a rolled Strength of 75 is over 100 points, and fine: a rollable budget has no total.
        self::assertSame(['str' => 75, 'san' => 90, 'lore' => 4], CharacterCreation::values($sheet, ['san' => 90, 'lore' => 4], CreationMethod::Roll, $random()));
        $this->assertRefused(CreationRefusal::OutsideBounds, static fn (): array => CharacterCreation::values($sheet, ['san' => 95, 'lore' => 1], CreationMethod::Roll, $random()));
    }

    #[Test]
    public function aPromotionRollsEveryDiceDefaultAndSpendsNothing(): void
    {
        $values = CharacterCreation::promoted($this->sheet(), new ScriptedRandomNumberGenerator(4, 5, 6, 1, 1, 2, 3, 3, 3));

        self::assertSame(['str' => 75, 'con' => 20, 'lore' => 0, 'sneak' => 0, 'luck' => 45], $values);
    }

    #[Test]
    public function aSheetKeepsItsBudgetsInOrderAndRefusesAFieldInTwo(): void
    {
        self::assertSame(['attributes', 'skills'], array_map(static fn (PointBudget $budget): string => $budget->key, $this->sheet()->budgets()));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('"str"');
        new SheetTemplate([new NumberField('str', 'Strength')], budgets: [new PointBudget(5, ['str'], key: 'a'), new PointBudget(5, ['str'], key: 'b')]);
    }

    #[Test]
    public function aSheetRefusesTwoBudgetsWithTheSameKeyOrABudgetOnANonNumberField(): void
    {
        try {
            new SheetTemplate([new NumberField('a', 'A'), new NumberField('b', 'B')], budgets: [new PointBudget(5, ['a'], key: 'x'), new PointBudget(5, ['b'], key: 'x')]);
            self::fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $invalid) {
            self::assertStringContainsString('"x"', $invalid->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        new SheetTemplate([new NumberField('a', 'A')], budgets: [new PointBudget(5, ['missing'], key: 'x')]);
    }

    /**
     * @param \Closure(): array<string, mixed> $create
     */
    private function assertRefused(CreationRefusal $refusal, \Closure $create): void
    {
        try {
            $create();
            self::fail('The Character was accepted.');
        } catch (InvalidCharacter $invalid) {
            self::assertSame($refusal, $invalid->refusal, $invalid->getMessage());
        }
    }
}
