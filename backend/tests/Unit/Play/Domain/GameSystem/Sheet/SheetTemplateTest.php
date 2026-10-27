<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\GameSystem\Sheet;

use App\Formula\Domain\Formula;
use App\Play\Domain\GameSystem\Sheet\Currency;
use App\Play\Domain\GameSystem\Sheet\DerivedField;
use App\Play\Domain\GameSystem\Sheet\InventoryField;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Play\Domain\GameSystem\Sheet\TextField;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SheetTemplate::class)]
final class SheetTemplateTest extends TestCase
{
    #[Test]
    public function itKeepsTheFieldsInDefinitionOrderAndFindsThemByKey(): void
    {
        $luck = new NumberField('luck', 'Luck');
        $role = new TextField('role', 'Role');

        $sheet = new SheetTemplate([$role, $luck]);

        self::assertSame([$role, $luck], $sheet->fields());
        self::assertSame($luck, $sheet->field('luck'));
        self::assertNull($sheet->field('ghost'));
        self::assertFalse($sheet->isEmpty());
    }

    #[Test]
    public function twoFieldsWithTheSameKeyAreRefusedInsteadOfKeepingTheLast(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Sheet field key "luck" is listed twice.');

        new SheetTemplate([new NumberField('luck', 'Luck'), new TextField('luck', 'Luck again')]);
    }

    #[Test]
    public function itListsTheDerivedFieldsAfterTheOnesTheyRead(): void
    {
        $sheet = new SheetTemplate([
            new DerivedField('guard', 'Guard', null, null, Formula::fromString('{dodge} + 1')),
            new NumberField('luck', 'Luck'),
            new DerivedField('dodge', 'Dodge', null, null, Formula::fromString('{luck} * 2')),
        ]);

        self::assertSame(['dodge', 'guard'], $sheet->derivedOrder());
        self::assertSame([], new SheetTemplate([new NumberField('luck', 'Luck')])->derivedOrder());
    }

    #[Test]
    public function derivedFieldsThatReadEachOtherAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('cycle');

        new SheetTemplate([
            new DerivedField('a', 'A', null, null, Formula::fromString('{b}')),
            new DerivedField('b', 'B', null, null, Formula::fromString('{a}')),
        ]);
    }

    #[Test]
    public function theCurrencyFloorIsTheMinOfItsFieldElseZero(): void
    {
        self::assertNull(new SheetTemplate([new NumberField('eddies', 'Eddies')])->currency());
        self::assertNull(new SheetTemplate([new NumberField('eddies', 'Eddies')])->currencyFloor());

        $currency = new Currency('eddies', 'eb');
        self::assertSame($currency, new SheetTemplate([new NumberField('eddies', 'Eddies')], currency: $currency)->currency());
        self::assertSame(0, new SheetTemplate([new NumberField('eddies', 'Eddies')], currency: $currency)->currencyFloor());
        self::assertSame(-500, new SheetTemplate([new NumberField('eddies', 'Eddies', min: -500)], currency: $currency)->currencyFloor());
    }

    #[Test]
    public function aCurrencyThatIsNotANumberFieldIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('The currency is "gear", which is not a number field.');

        new SheetTemplate([new InventoryField('gear', 'Gear')], currency: new Currency('gear'));
    }

    #[Test]
    public function aCurrencyOfAMissingFieldIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('The currency is "eddies", which is not a number field.');

        new SheetTemplate([], currency: new Currency('eddies'));
    }
}
