<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Infrastructure\GameSystem;

use App\Play\Domain\GameSystem\Flow\ShopStep;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\GearEntry;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\Sheet\Currency;
use App\Play\Domain\GameSystem\Sheet\InventoryField;
use App\Play\Infrastructure\GameSystem\GameSystemReleaseTranslator;
use App\Randomness\Domain\DiceExpression;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Play's anti-corruption layer reads the gear catalog, the sheet currency, the inventory fields and
 * the shop steps of ADR 0045. Run against examples/cpr-campaign-in-acts, whose Night Market is a shop.
 */
#[CoversClass(GameSystemReleaseTranslator::class)]
final class GameSystemReleaseTranslatorGearTest extends TestCase
{
    /**
     * examples/cpr-campaign-in-acts: the gear catalog, the currency "eddies", the inventories "gear"
     * and "cyberware", and the shop "stalls" of the Night Market (sceneTypes[20].play[0]).
     *
     * @return array<string, mixed>
     */
    public static function playable(): array
    {
        return ReleaseViews::fixtureContent('examples/cpr-campaign-in-acts');
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
        $content = ReleaseArrays::with($content, 'sceneTypes.20.play', []);

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
    public function itReadsTheShopOfTheNightMarketWithItsOfferInCatalogOrder(): void
    {
        $snapshot = $this->translate(self::playable());
        $stalls = $snapshot->sceneType($this->nightMarket())?->play->step('stalls');

        self::assertInstanceOf(ShopStep::class, $stalls);
        self::assertSame(array_map(static fn (GearEntry $entry): string => $entry->key, $snapshot->gear()), $this->offered($stalls));
        self::assertInstanceOf(DiceExpression::class, $stalls->stock);
        self::assertSame('1d4+2', $stalls->stock->notation());
        self::assertSame(50, $stalls->sellRate);
        self::assertFalse($stalls->mandatory);
    }

    #[Test]
    public function anOfferOfKeysAndCategoriesIsTheirUnionInCatalogOrder(): void
    {
        $content = ReleaseArrays::with(self::playable(), 'sceneTypes.20.play.0', ['key' => 'stalls', 'kind' => 'shop', 'title' => 'Shop', 'offer' => ['gear' => ['neural-link', 'kibble'], 'categories' => ['Food']], 'stock' => 2]);
        $stalls = $this->translate($content)->sceneType($this->nightMarket())?->play->step('stalls');

        self::assertInstanceOf(ShopStep::class, $stalls);
        self::assertSame(['kibble', 'real-coffee', 'neural-link'], $this->offered($stalls));
        self::assertSame(2, $stalls->stock);
        self::assertNull($stalls->sellRate);
    }

    /**
     * @return iterable<string, array{string, mixed, string}> the path under the shop "stalls", its value and the refusal
     */
    public static function malformedShops(): iterable
    {
        $at = 'sceneTypes[20].play[0]';
        yield 'an unknown property' => ['price', 10, $at.'.price: unknown property.'];
        yield 'an offer that is not an object' => ['offer', 'kibble', $at.'.offer: must be an object.'];
        yield 'an offer with an unknown property' => ['offer.items', ['kibble'], $at.'.offer.items: unknown property.'];
        yield 'an empty offer' => ['offer', ['gear' => [], 'categories' => null], $at.'.offer: must offer at least one gear entry or category.'];
        yield 'an unknown gear key' => ['offer', ['gear' => ['katana']], $at.'.offer.gear[0]: unknown gear "katana".'];
        yield 'a repeated gear key' => ['offer', ['gear' => ['kibble', 'kibble']], $at.'.offer.gear: must list at most 100 distinct gear keys.'];
        yield 'a category no gear has' => ['offer.categories.0', 'Guns', $at.'.offer.categories[0]: no gear has category "Guns".'];
        yield 'a repeated category' => ['offer.categories.1', 'Gear', $at.'.offer.categories: must list at most 20 distinct categories.'];
        yield 'a stock of zero' => ['stock', 0, $at.'.stock: must be between 1 and 50.'];
        yield 'a stock above the offer' => ['stock', 9, $at.'.stock: 9 entries on sale, the offer holds 8.'];
        yield 'a stock that is a string' => ['stock', '3', $at.'.stock: must be an integer or {dice}.'];
        yield 'a stock with bad dice' => ['stock.dice', 'd', $at.'.stock.dice: invalid dice notation'];
        yield 'a stock with an unknown property' => ['stock.max', 3, $at.'.stock.max: unknown property.'];
        yield 'a sell rate of zero' => ['sellRate', 0, $at.'.sellRate: must be between 1 and 100.'];
        yield 'a sell rate of 101' => ['sellRate', 101, $at.'.sellRate: must be between 1 and 100.'];
        yield 'a mandatory flag' => ['mandatory', false, $at.'.mandatory: a shop step is never skipped, an empty transaction leaves it.'];
        yield 'a sheet effect' => ['effects', [['kind' => 'sheet', 'field' => 'eddies', 'op' => 'add', 'value' => 1]], $at.'.effects[0]: a sheet effect is allowed only'];
    }

    #[Test]
    #[DataProvider('malformedShops')]
    public function itRefusesAMalformedShopWithItsPath(string $path, mixed $value, string $message): void
    {
        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains($message);

        $this->translate(ReleaseArrays::with(self::playable(), 'sceneTypes.20.play.0.'.$path, $value));
    }

    #[Test]
    public function aShopNeedsTheSheetCurrency(): void
    {
        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains('sceneTypes[20].play[0]: a shop step needs a sheet currency.');

        $this->translate(ReleaseArrays::without(self::playable(), 'sheet.currency'));
    }

    #[Test]
    public function everyOfferedEntryLandsInAnInventoryField(): void
    {
        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains('sceneTypes[20].play[0].offer: gear "cyberoptic" lands in no inventory field.');

        $this->translate(ReleaseArrays::with(self::playable(), 'sheet.fields.2.categories', ['Food']));
    }

    private function nightMarket(): string
    {
        /** @var array{sceneTypes: list<array{key: string}>} $content */
        $content = self::playable();

        return $content['sceneTypes'][20]['key'];
    }

    /**
     * @return list<string>
     */
    private function offered(ShopStep $step): array
    {
        return array_map(static fn (GearEntry $entry): string => $entry->key, $step->offer);
    }
}
