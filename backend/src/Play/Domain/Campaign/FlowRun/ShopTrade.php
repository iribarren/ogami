<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Characters\CharacterValues;
use App\Play\Domain\Campaign\InsufficientFunds;
use App\Play\Domain\Campaign\InventoryFull;
use App\Play\Domain\GameSystem\Flow\ShopStep;
use App\Play\Domain\GameSystem\GearEntry;
use App\Play\Domain\GameSystem\Sheet\Field;
use App\Play\Domain\GameSystem\Sheet\InventoryField;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;

/**
 * A shop step's transaction for one Character (ADR 0045), worked out on the Character's values
 * without changing them: the sales first, so their money and freed slots count for the purchases,
 * then the purchases. Only catalog stacks are sold, at the shop's sale price. A purchase lands on
 * the Character's stack of that gear in the first field that accepts it and holds one, else in the
 * first inventory field, in sheet order, that accepts it; it never spills into another field. The
 * whole transaction is refused when any part of it breaks a rule.
 *
 * @phpstan-type BoughtLine array{gear: string, name: string, quantity: int, price: int}
 * @phpstan-type SoldLine array{field: string, gear: string, name: string, quantity: int, price: int}
 */
final readonly class ShopTrade
{
    /**
     * @param list<BoughtLine>                                   $bought in request order, each with its unit price
     * @param list<SoldLine>                                     $sold   in request order, each with its unit price
     * @param int                                                $before the currency before the transaction
     * @param int                                                $after  the currency after it
     * @param array<string, int|list<array<string, int|string>>> $values the fields it changes, by key: the currency and the inventories
     */
    private function __construct(
        public array $bought,
        public array $sold,
        public int $before,
        public int $after,
        public array $values,
    ) {
    }

    /**
     * @param list<GearEntry>      $onSale the entries on sale now, in catalog order
     * @param array<string, mixed> $values the Character's values
     * @param list<ShopLine>       $buy
     * @param list<ShopLine>       $sell
     *
     * @throws InvalidStepResult when a quantity is outside 1..9,999, a gear or a stack is listed twice, a gear is not on sale, the shop does not buy, or a sale names no inventory field or more than its stack holds
     * @throws InsufficientFunds when the currency would end under its floor and lower than it was
     * @throws InventoryFull     when a purchase takes a field over its slots, its stacks or the quantity of a stack
     */
    public static function of(ShopStep $step, array $onSale, SheetTemplate $sheet, array $values, array $buy, array $sell): self
    {
        $currency = $sheet->currency() ?? throw new \LogicException(\sprintf('Shop step "%s" trades without a sheet currency.', $step->key));
        $money = $sheet->field($currency->field);
        if (!$money instanceof NumberField) {
            throw new \LogicException(\sprintf('The currency "%s" is not a number field.', $currency->field));
        }

        self::assertLines($step, $buy, static fn (ShopLine $line): string => $line->gear);
        self::assertLines($step, $sell, static fn (ShopLine $line): string => $line->field.' '.$line->gear);
        $stored = $values[$money->key] ?? CharacterValues::initial($money);
        $before = $amount = \is_int($stored) ? $stored : 0;
        $inventories = [];
        foreach ($sheet->fields() as $field) {
            if ($field instanceof InventoryField) {
                $inventories[$field->key] = self::stacks($values[$field->key] ?? []);
            }
        }

        $changed = [];
        $sold = [];
        foreach ($sell as $line) {
            if (null === $step->sellRate) {
                throw InvalidStepResult::shopDoesNotBuy($step->key);
            }

            $field = $sheet->field((string) $line->field);
            if (!$field instanceof InventoryField) {
                throw InvalidStepResult::notAnInventory($step->key, (string) $line->field);
            }

            $index = self::stackOf($inventories[$field->key], $line->gear);
            $held = null === $index ? 0 : (int) $inventories[$field->key][$index]['quantity'];
            $entry = $field->entry($line->gear);
            if (!$entry instanceof GearEntry || null === $index || $line->quantity > $held) {
                throw InvalidStepResult::overSell($step->key, $line->quantity, $line->gear, $field->key, $held);
            }

            if ($line->quantity === $held) {
                array_splice($inventories[$field->key], $index, 1);
            } else {
                $inventories[$field->key][$index]['quantity'] = $held - $line->quantity;
            }

            $price = $step->salePrice($entry);
            $amount += $price * $line->quantity;
            $sold[] = ['field' => $field->key, 'gear' => $entry->key, 'name' => $entry->name, 'quantity' => $line->quantity, 'price' => $price];
            $changed[$field->key] = $field;
        }

        $bought = [];
        foreach ($buy as $line) {
            $entry = array_find($onSale, static fn (GearEntry $entry): bool => $entry->key === $line->gear) ?? throw InvalidStepResult::notOnSale($step->key, $line->gear);
            $field = self::destination($sheet, $inventories, $entry) ?? throw new \LogicException(\sprintf('No inventory field accepts gear "%s".', $entry->key));
            $index = self::stackOf($inventories[$field->key], $entry->key);
            if (null === $index) {
                $inventories[$field->key][] = ['gear' => $entry->key, 'quantity' => $line->quantity];
            } else {
                $inventories[$field->key][$index]['quantity'] = (int) $inventories[$field->key][$index]['quantity'] + $line->quantity;
            }

            $amount -= $entry->cost * $line->quantity;
            $bought[] = ['gear' => $entry->key, 'name' => $entry->name, 'quantity' => $line->quantity, 'price' => $entry->cost];
            $changed[$field->key] = $field;
        }

        $floor = $sheet->currencyFloor() ?? 0;
        // A Character already under the floor (a check outcome took it there) may still trade up.
        if ($amount < $floor && $amount < $before) {
            throw new InsufficientFunds($money->key, $before, $amount, $floor);
        }

        foreach ($changed as $field) {
            self::assertFits($field, $inventories[$field->key]);
        }

        $after = max(-NumberField::LIMIT, min($money->max ?? NumberField::LIMIT, $amount));
        $values = [] === $changed ? [] : [$money->key => $after];
        foreach ($changed as $key => $field) {
            $values[$key] = $inventories[$key];
        }

        return new self($bought, $sold, $before, $after, $values);
    }

    /**
     * The names of the gear bought, in request order, joined with ", "; empty when nothing was: what "{step:key}" reads.
     */
    public function boughtNames(): string
    {
        return implode(', ', array_map(static fn (array $line): string => $line['name'], $this->bought));
    }

    /**
     * @param list<ShopLine>             $lines
     * @param \Closure(ShopLine): string $identity what a line may not repeat
     *
     * @throws InvalidStepResult
     */
    private static function assertLines(ShopStep $step, array $lines, \Closure $identity): void
    {
        $seen = [];
        foreach ($lines as $line) {
            if ($line->quantity < 1 || $line->quantity > CharacterValues::MAX_QUANTITY) {
                throw InvalidStepResult::shopQuantity($step->key, $line->gear, $line->quantity, CharacterValues::MAX_QUANTITY);
            }

            if (isset($seen[$identity($line)])) {
                throw InvalidStepResult::shopLineTwice($step->key, $line->gear);
            }

            $seen[$identity($line)] = true;
        }
    }

    /**
     * The stacks of an inventory value as CharacterValues keeps them; anything else holds none.
     *
     * @return list<array<string, int|string>>
     */
    private static function stacks(mixed $value): array
    {
        $stacks = [];
        foreach (\is_array($value) ? $value : [] as $stack) {
            if (!\is_array($stack)) {
                continue;
            }

            $kept = [];
            foreach ($stack as $name => $part) {
                if (\is_string($name) && (\is_int($part) || \is_string($part))) {
                    $kept[$name] = $part;
                }
            }

            $stacks[] = $kept;
        }

        return $stacks;
    }

    /**
     * @param list<array<string, int|string>> $stacks
     */
    private static function stackOf(array $stacks, string $gear): ?int
    {
        return array_find_key($stacks, static fn (array $stack): bool => ($stack['gear'] ?? null) === $gear);
    }

    /**
     * The field a purchase lands in: the first accepting field holding a stack of it, else the first accepting field.
     *
     * @param array<string, list<array<string, int|string>>> $inventories
     */
    private static function destination(SheetTemplate $sheet, array $inventories, GearEntry $entry): ?InventoryField
    {
        $accepting = array_values(array_filter($sheet->fields(), static fn (Field $field): bool => $field instanceof InventoryField && $field->entry($entry->key) instanceof GearEntry));
        /* @var list<InventoryField> $accepting */

        return array_find($accepting, static fn (InventoryField $field): bool => null !== self::stackOf($inventories[$field->key], $entry->key)) ?? $accepting[0] ?? null;
    }

    /**
     * @param list<array<string, int|string>> $stacks
     *
     * @throws InventoryFull
     */
    private static function assertFits(InventoryField $field, array $stacks): void
    {
        if (\count($stacks) > $field->maxStacks) {
            throw InventoryFull::stacks($field->key, $field->maxStacks, \count($stacks));
        }

        $used = 0;
        foreach ($stacks as $stack) {
            $quantity = (int) $stack['quantity'];
            if ($quantity > CharacterValues::MAX_QUANTITY) {
                throw InventoryFull::quantity($field->key, (string) ($stack['gear'] ?? $stack['name'] ?? ''), CharacterValues::MAX_QUANTITY, $quantity);
            }

            $used += $quantity * (int) ($stack['slots'] ?? $field->entry((string) ($stack['gear'] ?? ''))->slots ?? 0);
        }

        if (null !== $field->capacity && $used > $field->capacity) {
            throw InventoryFull::slots($field->key, $field->capacity, $used);
        }
    }
}
