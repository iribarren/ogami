<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\GameSystem\Sheet;

use App\Play\Domain\GameSystem\GearEntry;
use App\Play\Domain\GameSystem\Sheet\InventoryField;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InventoryField::class)]
#[CoversClass(GearEntry::class)]
final class InventoryFieldTest extends TestCase
{
    /**
     * @return list<GearEntry>
     */
    public static function catalog(): array
    {
        return [
            new GearEntry('pistol', 'Pistol', 50, 1, 'Gear'),
            new GearEntry('jacket', 'Jacket', 100, 2, 'Gear', 'Light armor.'),
            new GearEntry('kibble', 'Kibble', 10, 1, 'Food'),
            new GearEntry('optic', 'Cyberoptic', 500, 0, 'Chrome'),
            new GearEntry('box', 'Mystery box', 5),
        ];
    }

    #[Test]
    public function aGearEntryTakesOneSlotUnlessToldOtherwise(): void
    {
        self::assertSame(1, new GearEntry('pistol', 'Pistol', 50)->slots);
    }

    #[Test]
    public function withoutCategoriesItAcceptsEveryEntryWithOrWithoutOne(): void
    {
        $field = new InventoryField('gear', 'Gear', catalog: self::catalog());

        self::assertNull($field->capacity);
        self::assertSame(50, $field->maxStacks);
        self::assertNull($field->categories);
        self::assertSame(['pistol', 'jacket', 'kibble', 'optic', 'box'], array_map(static fn (GearEntry $entry): string => $entry->key, $field->gear()));
        self::assertSame('Jacket', $field->entry('jacket')?->name);
    }

    #[Test]
    public function withCategoriesItAcceptsTheEntriesOfThoseCategoriesOnly(): void
    {
        $field = new InventoryField('gear', 'Gear', capacity: 20, maxStacks: 5, categories: ['Gear', 'Food'], catalog: self::catalog());

        self::assertSame(['pistol', 'jacket', 'kibble'], array_map(static fn (GearEntry $entry): string => $entry->key, $field->gear()));
        self::assertNull($field->entry('optic'), 'Chrome is not accepted.');
        self::assertNull($field->entry('box'), 'Gear without a category is not accepted.');
        self::assertNull($field->entry('ghost'));
    }

    /**
     * @return iterable<string, array{\Closure(): InventoryField, string}> the field to build and the message
     */
    public static function refusals(): iterable
    {
        yield 'a capacity of zero' => [static fn (): InventoryField => new InventoryField('gear', 'Gear', capacity: 0, catalog: self::catalog()), 'Inventory field "gear" holds 1 to 999 slots.'];
        yield 'a capacity of 1000' => [static fn (): InventoryField => new InventoryField('gear', 'Gear', capacity: 1000, catalog: self::catalog()), 'Inventory field "gear" holds 1 to 999 slots.'];
        yield 'maxStacks of zero' => [static fn (): InventoryField => new InventoryField('gear', 'Gear', maxStacks: 0, catalog: self::catalog()), 'Inventory field "gear" holds 1 to 100 stacks.'];
        yield 'maxStacks of 101' => [static fn (): InventoryField => new InventoryField('gear', 'Gear', maxStacks: 101, catalog: self::catalog()), 'Inventory field "gear" holds 1 to 100 stacks.'];
        yield 'an empty category list' => [static fn (): InventoryField => new InventoryField('gear', 'Gear', categories: [], catalog: self::catalog()), 'Inventory field "gear" accepts 1 to 20 distinct categories.'];
        yield 'a repeated category' => [static fn (): InventoryField => new InventoryField('gear', 'Gear', categories: ['Gear', 'Gear'], catalog: self::catalog()), 'Inventory field "gear" accepts 1 to 20 distinct categories.'];
        yield '21 categories' => [static fn (): InventoryField => new InventoryField('gear', 'Gear', categories: array_map(static fn (int $n): string => 'C'.$n, range(1, 21)), catalog: self::catalog()), 'Inventory field "gear" accepts 1 to 20 distinct categories.'];
        yield 'a category no entry has' => [static fn (): InventoryField => new InventoryField('gear', 'Gear', categories: ['Chrom'], catalog: self::catalog()), 'Inventory field "gear" accepts category "Chrom", which no gear has.'];
    }

    /**
     * @param \Closure(): InventoryField $build
     */
    #[Test]
    #[DataProvider('refusals')]
    public function aFieldBreakingItsLimitsIsRefused(\Closure $build, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($message);

        $build();
    }
}
