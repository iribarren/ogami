<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\Characters;

use App\Formula\Domain\Formula;
use App\Formula\Domain\Reference;
use App\Play\Domain\Campaign\Characters\CharacterValues;
use App\Play\Domain\Campaign\Characters\InvalidCharacter;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\GameSystem\Sheet\ConditionOption;
use App\Play\Domain\GameSystem\Sheet\ConditionsField;
use App\Play\Domain\GameSystem\Sheet\DerivedField;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The value of a conditions field (ADR 0041) on a sheet of agility, "physical" (hurt, exhausted,
 * poisoned in that order) and strain = the count marked of physical.
 */
#[CoversClass(ConditionsField::class)]
#[CoversClass(CharacterValues::class)]
#[CoversClass(SheetValues::class)]
final class ConditionsValuesTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, list<string>}> the given value and the value stored
     */
    public static function validValues(): iterable
    {
        yield 'nothing marked' => [[], []];
        yield 'one option' => [['exhausted'], ['exhausted']];
        yield 'every option' => [['hurt', 'exhausted', 'poisoned'], ['hurt', 'exhausted', 'poisoned']];
        yield 'stored in the declared order' => [['poisoned', 'hurt'], ['hurt', 'poisoned']];
    }

    /**
     * @param list<string> $stored
     */
    #[Test]
    #[DataProvider('validValues')]
    public function aListOfDeclaredOptionsIsStoredInTheDeclaredOrder(mixed $given, array $stored): void
    {
        self::assertSame($stored, CharacterValues::resolve($this->sheet(), ['physical' => $given])['physical']);
    }

    /**
     * @return iterable<string, array{mixed, string}> the given value and the message
     */
    public static function invalidValues(): iterable
    {
        yield 'an unknown option' => [['ghost'], 'Field "physical" has no option "ghost".'];
        yield 'a known option with another case' => [['Hurt'], 'Field "physical" has no option "Hurt".'];
        yield 'a duplicate' => [['hurt', 'hurt'], 'Field "physical" marks an option twice.'];
        yield 'a duplicate among others' => [['hurt', 'exhausted', 'hurt'], 'Field "physical" marks an option twice.'];
        yield 'a string' => ['hurt', 'Field "physical" must be a list of option keys.'];
        yield 'a number' => [2, 'Field "physical" must be a list of option keys.'];
        yield 'an object' => [['hurt' => true], 'Field "physical" must be a list of option keys.'];
        yield 'null' => [null, 'Field "physical" must be a list of option keys.'];
        yield 'a number in the list' => [[1], 'Field "physical" has no option "int".'];
        yield 'a nested list' => [[['hurt']], 'Field "physical" has no option "array".'];
    }

    #[Test]
    #[DataProvider('invalidValues')]
    public function aValueThatBreaksTheFieldIsRefused(mixed $given, string $message): void
    {
        $this->expectException(InvalidCharacter::class);
        $this->expectExceptionMessageIsOrContains($message);

        CharacterValues::resolve($this->sheet(), ['physical' => $given]);
    }

    #[Test]
    public function aCharacterStartsWithNothingMarked(): void
    {
        self::assertSame(['agility' => 3, 'physical' => []], CharacterValues::resolve($this->sheet(), []));
        self::assertSame([], CharacterValues::initial(new ConditionsField('c', 'C', options: [new ConditionOption('a', 'A')])));
    }

    #[Test]
    public function aStoredListIsWellFormedWithoutTheTemplate(): void
    {
        self::assertSame(['physical' => ['hurt']], CharacterValues::assertWellFormed(['physical' => ['hurt']]));
        self::assertSame(['physical' => []], CharacterValues::assertWellFormed(['physical' => []]));
    }

    #[Test]
    public function aFormulaReadsTheCountMarked(): void
    {
        $values = SheetValues::of($this->sheet(), ['physical' => ['hurt', 'poisoned']], []);

        self::assertSame(2, $values->value(Reference::field('physical')));
        self::assertSame(['strain' => 2], $values->derived());
    }

    #[Test]
    public function aCharacterStoredBeforeTheFieldExistedReadsAsNothingMarked(): void
    {
        $values = SheetValues::of($this->sheet(), ['agility' => 4], []);

        self::assertSame(0, $values->value(Reference::field('physical')));
        self::assertSame(['strain' => 0], $values->derived());
    }

    #[Test]
    public function aConditionsFieldHasAtLeastOneOptionAndUniqueKeys(): void
    {
        try {
            new ConditionsField('c', 'C');
            self::fail('A conditions field needs an option.');
        } catch (\InvalidArgumentException $invalid) {
            self::assertSame('Conditions field "c" declares no option.', $invalid->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Conditions field "c" lists an option key twice.');

        new ConditionsField('c', 'C', options: [new ConditionOption('a', 'A'), new ConditionOption('a', 'Again')]);
    }

    private function sheet(): SheetTemplate
    {
        return new SheetTemplate([
            new NumberField('agility', 'Agility', min: 1, max: 5, default: 3),
            new ConditionsField('physical', 'Physical', options: [new ConditionOption('hurt', 'Hurt'), new ConditionOption('exhausted', 'Exhausted'), new ConditionOption('poisoned', 'Poisoned')]),
            new DerivedField('strain', 'Strain', null, null, Formula::fromString('{physical}')),
        ]);
    }
}
