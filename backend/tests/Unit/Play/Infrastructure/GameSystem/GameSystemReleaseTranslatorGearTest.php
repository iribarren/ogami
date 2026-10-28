<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Infrastructure\GameSystem;

use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\GearEntry;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\Sheet\Currency;
use App\Play\Domain\GameSystem\Sheet\InventoryField;
use App\Play\Infrastructure\GameSystem\GameSystemReleaseTranslator;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Play's anti-corruption layer reads the gear catalog, the sheet currency and the inventory fields
 * of ADR 0045 and still refuses shop steps (play-items-economy slice 3). Run against
 * examples/cpr-campaign-in-acts without its Night Market.
 */
#[CoversClass(GameSystemReleaseTranslator::class)]
final class GameSystemReleaseTranslatorGearTest extends TestCase
{
    /**
     * examples/cpr-campaign-in-acts without its shop: the gear catalog, the currency "eddies" and
     * the inventories "gear" and "cyberware" stay.
     *
     * @return array<string, mixed>
     */
    public static function playable(): array
    {
        /** @var array<string, mixed> $content */
        $content = ReleaseArrays::with(ReleaseViews::fixtureContent('examples/cpr-campaign-in-acts'), 'sceneTypes.20.play', []);

        return $content;
    }

    /**
     * @param array<mixed> $content
     */
    private function translate(array $content): GameSystemSnapshot
    {
        /** @var array<string, mixed> $typed */
        $typed = $content;

        return new GameSystemReleaseTranslator()->translate(ReleaseViews::of($typed));
    }

    #[Test]
    public function itReadsTheGearCatalog(): void
    {
        $gear = $this->translate(self::playable())->gear();

        self::assertCount(8, $gear);
        self::assertEquals(new GearEntry('used-pistol', 'Used pistol', 50, 1, 'Gear'), $gear[0]);
        self::assertEquals(new GearEntry('armor-jacket', 'Armor jacket', 100, 2, 'Gear', 'Light armor that passes for street wear.'), $gear[1]);
        self::assertEquals(new GearEntry('real-coffee', 'Real coffee', 20, 0, 'Food'), $gear[5]);
        self::assertEquals($gear[1], $this->translate(self::playable())->gearEntry('armor-jacket'));
        self::assertNull($this->translate(self::playable())->gearEntry('katana'));
    }

    #[Test]
    public function itReadsTheCurrencyAndItsFloor(): void
    {
        $sheet = $this->translate(self::playable())->sheet();

        self::assertEquals(new Currency('eddies', 'eb'), $sheet->currency());
        self::assertSame(0, $sheet->currencyFloor());
        self::assertSame(-100, $this->translate(ReleaseArrays::with(self::playable(), 'sheet.fields.0.min', -100))->sheet()->currencyFloor());
        self::assertNull($this->translate(ReleaseArrays::with(self::playable(), 'sheet.currency.unit', null))->sheet()->currency()?->unit);
    }

    #[Test]
    public function itReadsTheInventoryFieldsWithTheEntriesTheyAccept(): void
    {
        $sheet = $this->translate(self::playable())->sheet();
        $gear = $sheet->field('gear');
        $cyberware = $sheet->field('cyberware');

        self::assertInstanceOf(InventoryField::class, $gear);
        self::assertSame(20, $gear->capacity);
        self::assertSame(50, $gear->maxStacks);
        self::assertSame(['Gear', 'Food'], $gear->categories);
        self::assertSame(['used-pistol', 'armor-jacket', 'burner-agent', 'climbing-gear', 'kibble', 'real-coffee'], array_map(static fn (GearEntry $entry): string => $entry->key, $gear->gear()));
        self::assertInstanceOf(InventoryField::class, $cyberware);
        self::assertNull($cyberware->capacity);
        self::assertSame(10, $cyberware->maxStacks);
        self::assertSame(['cyberoptic', 'neural-link'], array_map(static fn (GearEntry $entry): string => $entry->key, $cyberware->gear()));
    }

    #[Test]
    public function anInventoryWithoutCategoriesAcceptsTheWholeCatalog(): void
    {
        $field = $this->translate(ReleaseArrays::without(self::playable(), 'sheet.fields.1.categories'))->sheet()->field('gear');

        self::assertInstanceOf(InventoryField::class, $field);
        self::assertNull($field->categories);
        self::assertCount(8, $field->gear());
    }

    #[Test]
    public function aReleaseWithoutGearCurrencyOrInventoriesIsPlayable(): void
    {
        $content = ReleaseArrays::with(ReleaseArrays::without(self::playable(), 'gear'), 'sheet', ['fields' => [['key' => 'eddies', 'label' => 'Eddies', 'type' => 'number', 'min' => 0, 'default' => 500]]]);

        $snapshot = $this->translate($content);

        self::assertSame([], $snapshot->gear());
        self::assertNull($snapshot->sheet()->currency());
        self::assertFalse($snapshot->sheet()->isEmpty());
    }

    /**
     * @return iterable<string, array{string, mixed, string}> the path set on the playable release, its value and the refusal
     */
    public static function malformedParts(): iterable
    {
        yield 'a catalog that is not a list' => ['gear', ['used-pistol' => []], 'gear: must be a list.'];
        yield 'a catalog of 501 entries' => ['gear', array_map(static fn (int $n): array => ['key' => 'g'.$n, 'name' => 'G'.$n, 'cost' => 1], range(1, 501)), 'gear: at most 500 gear entries.'];
        yield 'an entry with an unknown property' => ['gear.3.weight', 2, 'gear[3].weight: unknown property.'];
        yield 'an entry without a cost' => ['gear.3.cost', null, 'gear[3].cost: must be an integer.'];
        yield 'a cost that is a string' => ['gear.3.cost', '50', 'gear[3].cost: must be an integer.'];
        yield 'a negative cost' => ['gear.3.cost', -1, 'gear[3].cost: must be between 0 and 1000000.'];
        yield 'slots above 100' => ['gear.3.slots', 101, 'gear[3].slots: must be between 0 and 100.'];
        yield 'a blank name' => ['gear.3.name', ' ', 'gear[3].name: must not be blank.'];
        yield 'a long name' => ['gear.3.name', str_repeat('a', 61), 'gear[3].name: must be at most 60 characters.'];
        yield 'a name repeated in another case' => ['gear.3.name', ' used PISTOL', 'gear[3].name: duplicate gear name "used PISTOL".'];
        yield 'a long category' => ['gear.3.category', str_repeat('a', 41), 'gear[3].category: must be at most 40 characters.'];
        yield 'a long description' => ['gear.3.description', str_repeat('a', 501), 'gear[3].description: must be at most 500 characters.'];
        yield 'a bad key' => ['gear.3.key', 'Climbing Gear', 'gear[3].key: must be 1 to 64 characters among a-z, 0-9 and "-".'];
        yield 'a duplicate key' => ['gear.3.key', 'used-pistol', 'gear[3].key: duplicate key "used-pistol".'];
        yield 'a currency that is not an object' => ['sheet.currency', 'eddies', 'sheet.currency: must be an object.'];
        yield 'a currency with an unknown property' => ['sheet.currency.symbol', '€', 'sheet.currency.symbol: unknown property.'];
        yield 'a currency of an unknown field' => ['sheet.currency.field', 'cash', 'sheet.currency.field: "cash" is not a number field.'];
        yield 'a currency on an inventory' => ['sheet.currency.field', 'gear', 'sheet.currency.field: "gear" is not a number field.'];
        yield 'a unit of 11 characters' => ['sheet.currency.unit', str_repeat('e', 11), 'sheet.currency.unit: must be at most 10 characters.'];
        yield 'a capacity of zero' => ['sheet.fields.1.capacity', 0, 'sheet.fields[1].capacity: must be between 1 and 999.'];
        yield 'a capacity of 1000' => ['sheet.fields.1.capacity', 1000, 'sheet.fields[1].capacity: must be between 1 and 999.'];
        yield 'maxStacks of 101' => ['sheet.fields.2.maxStacks', 101, 'sheet.fields[2].maxStacks: must be between 1 and 100.'];
        yield 'categories that are not a list' => ['sheet.fields.1.categories', 'Gear', 'sheet.fields[1].categories: must be a list.'];
        yield 'an empty categories list' => ['sheet.fields.2.categories', [], 'sheet.fields[2]: Inventory field "cyberware" accepts 1 to 20 distinct categories.'];
        yield 'a category no entry has' => ['sheet.fields.2.categories.0', 'Chrom', 'sheet.fields[2]: Inventory field "cyberware" accepts category "Chrom", which no gear has.'];
        yield 'a repeated category' => ['sheet.fields.1.categories.1', 'Gear', 'sheet.fields[1]: Inventory field "gear" accepts 1 to 20 distinct categories.'];
        yield 'a list property on an inventory' => ['sheet.fields.1.maxItems', 5, 'sheet.fields[1].maxItems: unknown property.'];
        yield 'a sheet effect on an inventory' => ['sceneTypes.21.play.1.options.0.effects.0.field', 'gear', 'sceneTypes[21].play[1].options[0].effects[0].field: field "gear" is a inventory field, a sheet effect changes number and track fields only.'];
        yield 'a derived field reading an inventory' => ['sheet.fields.3', ['key' => 'load', 'label' => 'Load', 'type' => 'derived', 'formula' => '{gear}'], 'sheet.fields[3].formula: {gear}: field "gear" is a text, list or inventory field, a formula reads number, track, conditions and derived fields only.'];
        yield 'a track start max reading an inventory' => ['sheet.fields.3', ['key' => 'hp', 'label' => 'Hit points', 'type' => 'track', 'startMax' => ['formula' => '{gear}']], 'sheet.fields[3].startMax.formula: {gear}: field "gear" is not a number or derived field, a start max formula reads number and derived fields only.'];
        yield 'a point budget covering an inventory' => ['sheet.pointBudget', ['total' => 10, 'fields' => ['gear']], 'sheet.pointBudget: The point budget covers "gear", which is not a number field.'];
        yield 'a check modifier reading an inventory' => ['checks', [['key' => 'skill', 'label' => 'Skill', 'dice' => '1d10', 'modifier' => '{gear}', 'bands' => [['key' => 'fail', 'label' => 'Fail', 'upTo' => 5], ['key' => 'hit', 'label' => 'Hit']]]], 'checks[0].modifier: {gear}: field "gear" is a text, list or inventory field'];
    }

    #[Test]
    #[DataProvider('malformedParts')]
    public function itRefusesAMalformedPartWithItsPath(string $path, mixed $value, string $message): void
    {
        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains($message);

        $this->translate(ReleaseArrays::with(self::playable(), $path, $value));
    }

    #[Test]
    public function itStillRefusesAShopStep(): void
    {
        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains('sceneTypes[20].play[0].kind: shop steps are not played yet (ADR 0045).');

        $this->translate(ReleaseArrays::with(self::playable(), 'sceneTypes.20.play', [['key' => 'stalls', 'kind' => 'shop', 'title' => 'Shop', 'offer' => ['gear' => ['kibble']]]]));
    }
}
