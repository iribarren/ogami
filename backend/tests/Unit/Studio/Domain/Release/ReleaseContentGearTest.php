<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\Release;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\Version2\Gear;
use App\Studio\Domain\Release\Version2\ReleaseVersion2;
use App\Studio\Domain\Release\Version2\SheetFields;
use App\Studio\Domain\Release\Version2\Steps;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The parts of ADR 0045, run against examples/cpr-campaign-in-acts: the gear catalog, the sheet
 * currency "eddies", the inventories "gear" (sheet.fields[1], Gear and Food) and "cyberware"
 * (sheet.fields[2], Chrome), the Night Market shop (sceneTypes[20].play[0]) and the rent choices
 * of Month's end, whose options spend the currency. All are additive, so releases without them
 * keep their hash.
 */
#[CoversClass(ReleaseContent::class)]
#[CoversClass(Gear::class)]
#[CoversClass(SheetFields::class)]
#[CoversClass(Steps::class)]
#[CoversClass(ReleaseVersion2::class)]
final class ReleaseContentGearTest extends TestCase
{
    private const string FIXTURE = 'examples/cpr-campaign-in-acts';
    private const string SHOP = 'sceneTypes.20.play.0';

    /**
     * @return iterable<string, array{string, string}> fixtures without the new parts and their hash before ADR 0045
     */
    public static function hashesBefore(): iterable
    {
        yield 'sheet' => ['valid/v2-sheet', '80e866bbc910b09ca4b4b6ae2d6d8348e776e5ca8573c300f84a46bbf4d9a1c6'];
        yield 'every part' => ['valid/v2-every-part', 'd4ea555f0fa39a6c88d818de49ac5e60564559b1995ab0ac76febbc9c834a7b5'];
        yield 'hunger dice' => ['valid/v2-hunger-dice', 'd8e1198542fc10871c005e0230128b3dc4c2cacb37ab8bb656b8c3cbd3d3876e'];
        yield 'west marches' => ['examples/west-marches', 'd5bef65a2e5f459e0c33d30425c9c9e073d66f8b3fbbfe5dc24c05b7037eeaf7'];
    }

    #[Test]
    #[DataProvider('hashesBefore')]
    public function aReleaseWithoutTheNewPartsKeepsItsHash(string $fixture, string $hash): void
    {
        self::assertSame($hash, ReleaseContent::fromArray(ReleaseArrays::fixture($fixture))->hash());
    }

    #[Test]
    public function anEmptyOrNullCatalogAndNullPartsAreLeftOut(): void
    {
        $sheet = ReleaseArrays::fixture('valid/v2-sheet');
        $hash = ReleaseContent::fromArray($sheet)->hash();

        self::assertSame($hash, ReleaseContent::fromArray(ReleaseArrays::with($sheet, 'gear', []))->hash());
        self::assertSame($hash, ReleaseContent::fromArray(ReleaseArrays::with($sheet, 'gear', null))->hash());
        self::assertSame($hash, ReleaseContent::fromArray(ReleaseArrays::with($sheet, 'sheet.currency', null))->hash());
        self::assertArrayNotHasKey('gear', ReleaseContent::fromArray(ReleaseArrays::with($sheet, 'gear', []))->toArray());
    }

    #[Test]
    public function theNewPartsKeepTheirCanonicalFormAndLeaveDefaultsOut(): void
    {
        $release = ReleaseArrays::fixture(self::FIXTURE);
        $release = ReleaseArrays::with($release, 'gear.0.slots', 1);
        $release = ReleaseArrays::with($release, 'gear.0.description', null);
        $release = ReleaseArrays::with($release, 'sheet.fields.1.maxStacks', 50);
        $release = ReleaseArrays::with($release, 'sheet.currency.unit', null);
        $release = ReleaseArrays::with($release, self::SHOP.'.offer', ['gear' => [], 'categories' => ['Gear', 'Food', 'Chrome']]);
        $release = ReleaseArrays::with($release, self::SHOP.'.sellRate', null);

        /** @var array{gear: list<array<string, mixed>>, sheet: array{fields: list<array<string, mixed>>, currency: array<string, mixed>}, sceneTypes: list<array{play: list<array<string, mixed>>}>} $content */
        $content = ReleaseContent::fromArray($release)->toArray();

        self::assertSame(['key' => 'used-pistol', 'name' => 'Used pistol', 'cost' => 50, 'category' => 'Gear'], $content['gear'][0]);
        self::assertSame(['key' => 'armor-jacket', 'name' => 'Armor jacket', 'cost' => 100, 'slots' => 2, 'category' => 'Gear', 'description' => 'Light armor that passes for street wear.'], $content['gear'][1]);
        self::assertSame(['key' => 'gear', 'label' => 'Gear', 'type' => 'inventory', 'capacity' => 20, 'categories' => ['Gear', 'Food']], $content['sheet']['fields'][1]);
        self::assertSame(['field' => 'eddies'], $content['sheet']['currency']);
        self::assertSame(
            ['key' => 'stalls', 'kind' => 'shop', 'title' => 'What do you buy or sell?', 'prompt' => 'A few stalls are open tonight.', 'offer' => ['categories' => ['Gear', 'Food', 'Chrome']], 'stock' => ['dice' => '1d4+2']],
            $content['sceneTypes'][20]['play'][0],
        );
    }

    #[Test]
    public function aDiceStockMayExceedTheOfferAndAnIntegerStockMayEqualIt(): void
    {
        $release = ReleaseArrays::with(ReleaseArrays::fixture(self::FIXTURE), self::SHOP.'.offer', ['gear' => ['used-pistol', 'kibble']]);

        self::assertNotSame('', ReleaseContent::fromArray(ReleaseArrays::with($release, self::SHOP.'.stock', ['dice' => '1d10']))->hash());
        self::assertNotSame('', ReleaseContent::fromArray(ReleaseArrays::with($release, self::SHOP.'.stock', 2))->hash());
    }

    #[Test]
    public function anOfferMayMixKeysAndCategories(): void
    {
        $release = ReleaseArrays::with(ReleaseArrays::fixture(self::FIXTURE), self::SHOP.'.offer', ['gear' => ['cyberoptic'], 'categories' => ['Food']]);

        self::assertNotSame('', ReleaseContent::fromArray(ReleaseArrays::with($release, self::SHOP.'.stock', 3))->hash());
    }

    #[Test]
    public function aShopMaySitInAPhaseHook(): void
    {
        /** @var array{sceneTypes: list<array{play: list<array<string, mixed>>}>} $release */
        $release = ReleaseArrays::fixture(self::FIXTURE);
        $shop = $release['sceneTypes'][20]['play'][0];
        $release = ReleaseArrays::with($release, 'flows.0.phases.0.sessionClosing', [$shop]);

        self::assertNotSame('', ReleaseContent::fromArray($release)->hash());
    }

    #[Test]
    public function anInventoryWithoutCategoriesAcceptsGearWithoutOne(): void
    {
        $release = ReleaseArrays::with(ReleaseArrays::fixture(self::FIXTURE), 'gear.8', ['key' => 'mystery-box', 'name' => 'Mystery box', 'cost' => 5]);
        $release = ReleaseArrays::with($release, self::SHOP.'.offer.gear', ['mystery-box']);

        self::assertNotSame('', ReleaseContent::fromArray(ReleaseArrays::without($release, 'sheet.fields.1.categories'))->hash());

        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains('sceneTypes[20].play[0].offer.gear[0]: no inventory field accepts gear "mystery-box", which has no category');

        ReleaseContent::fromArray($release);
    }

    /**
     * @return iterable<string, array{string, mixed, string}> the path changed in the example, its new value and the message
     */
    public static function refusals(): iterable
    {
        $shop = self::SHOP;
        $shopPath = 'sceneTypes[20].play[0]';

        yield 'a catalog that is not a list' => ['gear', ['used-pistol' => []], 'gear: must be a list'];
        yield 'a catalog of 501 entries' => ['gear', array_map(static fn (int $n): array => ['key' => 'g'.$n, 'name' => 'G'.$n, 'cost' => 1], range(1, 501)), 'gear: at most 500 gear entries, 501 given'];
        yield 'an entry with an unknown property' => ['gear.3.weight', 2, 'gear[3].weight: unknown property'];
        yield 'an entry without a cost' => ['gear.3', ['key' => 'climbing-gear', 'name' => 'Climbing gear'], 'gear[3].cost: required'];
        yield 'a negative cost' => ['gear.3.cost', -1, 'gear[3].cost: must be at least 0, -1 given'];
        yield 'a cost above 1,000,000' => ['gear.3.cost', 1_000_001, 'gear[3].cost: must be at most 1000000, 1000001 given'];
        yield 'a cost that is a string' => ['gear.3.cost', '50', 'gear[3].cost: must be an integer'];
        yield 'slots above 100' => ['gear.3.slots', 101, 'gear[3].slots: must be at most 100, 101 given'];
        yield 'a blank name' => ['gear.3.name', ' ', 'gear[3].name: must not be blank'];
        yield 'a name of 61 characters' => ['gear.3.name', str_repeat('a', 61), 'gear[3].name: must be at most 60 characters, 61 given'];
        yield 'a category of 41 characters' => ['gear.3.category', str_repeat('a', 41), 'gear[3].category: must be at most 40 characters, 41 given'];
        yield 'a description of 501 characters' => ['gear.3.description', str_repeat('a', 501), 'gear[3].description: must be at most 500 characters, 501 given'];
        yield 'a duplicate key' => ['gear.3.key', 'used-pistol', 'gear[3].key: duplicate gear key "used-pistol"'];
        yield 'a name repeated in another case' => ['gear.3.name', ' used PISTOL', 'gear[3].name: duplicate gear name "used PISTOL", names are unique trimmed and ignoring case'];
        yield 'a currency that is not an object' => ['sheet.currency', 'eddies', 'sheet.currency: must be an object'];
        yield 'a currency with an unknown property' => ['sheet.currency.symbol', '€', 'sheet.currency.symbol: unknown property'];
        yield 'a currency of an unknown field' => ['sheet.currency.field', 'cash', 'sheet.currency.field: unknown sheet field "cash"'];
        yield 'a currency on an inventory' => ['sheet.currency.field', 'gear', 'sheet.currency.field: field "gear" is an inventory field, the currency is a number field'];
        yield 'a unit of 11 characters' => ['sheet.currency.unit', str_repeat('e', 11), 'sheet.currency.unit: must be at most 10 characters, 11 given'];
        yield 'a capacity of zero' => ['sheet.fields.1.capacity', 0, 'sheet.fields[1].capacity: must be at least 1, 0 given'];
        yield 'a capacity of 1000' => ['sheet.fields.1.capacity', 1000, 'sheet.fields[1].capacity: must be at most 999, 1000 given'];
        yield 'maxStacks of 101' => ['sheet.fields.2.maxStacks', 101, 'sheet.fields[2].maxStacks: must be at most 100, 101 given'];
        yield 'an empty categories list' => ['sheet.fields.2.categories', [], 'sheet.fields[2].categories: at least 1 categories, 0 given'];
        yield 'a category no entry has' => ['sheet.fields.2.categories.0', 'Chrom', 'sheet.fields[2].categories[0]: no gear of the catalog has category "Chrom"'];
        yield 'a repeated category' => ['sheet.fields.1.categories.1', 'Gear', 'sheet.fields[1].categories[1]: duplicate category "Gear"'];
        yield 'a list property on an inventory' => ['sheet.fields.1.maxItems', 5, 'sheet.fields[1].maxItems: unknown property'];
        yield 'a sheet effect on an inventory' => ['sceneTypes.21.play.1.options.0.effects.0.field', 'gear', 'sceneTypes[21].play[1].options[0].effects[0].field: field "gear" is an inventory field, a sheet effect changes number and track fields only'];
        yield 'a shop without an offer' => [$shop, ['key' => 'stalls', 'kind' => 'shop', 'title' => 'Shop'], $shopPath.'.offer: required'];
        yield 'an empty offer' => [$shop.'.offer', ['gear' => [], 'categories' => null], $shopPath.'.offer: give gear or categories, at least one entry'];
        yield 'an offer with an unknown property' => [$shop.'.offer.services', ['rent'], $shopPath.'.offer.services: unknown property'];
        yield 'an offer of an unknown key' => [$shop.'.offer.gear', ['used-pistol', 'katana'], $shopPath.'.offer.gear[1]: unknown gear "katana"'];
        yield 'an offer repeating a key' => [$shop.'.offer.gear', ['kibble', 'kibble'], $shopPath.'.offer.gear[1]: duplicate gear "kibble"'];
        yield 'an offer of an unknown category' => [$shop.'.offer.categories.2', 'chrome', $shopPath.'.offer.categories[2]: no gear of the catalog has category "chrome"'];
        yield 'an offer repeating a category' => [$shop.'.offer.categories.2', 'Gear', $shopPath.'.offer.categories[2]: duplicate category "Gear"'];
        yield 'an offer of 101 keys' => [$shop.'.offer.gear', array_fill(0, 101, 'kibble'), $shopPath.'.offer.gear: at most 100 gear entries, 101 given'];
        yield 'an offered entry no inventory accepts' => ['sheet.fields.2.categories', ['Food'], $shopPath.'.offer.categories[2]: no inventory field accepts gear "cyberoptic" of category "Chrome"'];
        yield 'a stock of zero' => [$shop.'.stock', 0, $shopPath.'.stock: must be at least 1, 0 given'];
        yield 'a stock of 51' => [$shop.'.stock', 51, $shopPath.'.stock: must be at most 50, 51 given'];
        yield 'a stock above the offer' => [$shop.'.stock', 9, $shopPath.'.stock: must be at most 8, the entries the offer resolves to, 9 given'];
        yield 'a stock that is a string' => [$shop.'.stock', '3', $shopPath.'.stock: must be an integer or {dice: string}'];
        yield 'a stock of bad dice' => [$shop.'.stock', ['dice' => '1d'], $shopPath.'.stock.dice: invalid dice notation'];
        yield 'a sell rate of zero' => [$shop.'.sellRate', 0, $shopPath.'.sellRate: must be at least 1, 0 given'];
        yield 'a sell rate of 101' => [$shop.'.sellRate', 101, $shopPath.'.sellRate: must be at most 100, 101 given'];
        yield 'a mandatory shop' => [$shop.'.mandatory', true, $shopPath.'.mandatory: a shop step is never skipped, an empty transaction leaves it'];
        yield 'a shop that is not mandatory' => [$shop.'.mandatory', false, $shopPath.'.mandatory: a shop step is never skipped, an empty transaction leaves it'];
        yield 'a sheet effect on a shop' => [$shop.'.effects', [['kind' => 'sheet', 'field' => 'eddies', 'op' => 'add', 'value' => 10]], $shopPath.'.effects[0]: a sheet effect is allowed only in'];
        yield 'a shop without a currency' => ['sheet.currency', null, $shopPath.': a shop step needs sheet.currency'];
        yield 'a shop in a setup list' => ['sceneTypes.20.setup', [['key' => 'stalls', 'kind' => 'shop', 'title' => 'Shop', 'offer' => ['gear' => ['kibble']]]], 'sceneTypes[20].setup[0]: a shop step needs a Character, which a Scene Type\'s setup list has none of'];
    }

    #[Test]
    #[DataProvider('refusals')]
    public function aMalformedPartIsRefusedWithItsPath(string $path, mixed $value, string $message): void
    {
        $release = ReleaseArrays::with(ReleaseArrays::fixture(self::FIXTURE), $path, $value);

        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains($message);

        ReleaseContent::fromArray($release);
    }

    #[Test]
    public function aShopInAPhaseHookIsCheckedThere(): void
    {
        $release = ReleaseArrays::with(ReleaseArrays::fixture(self::FIXTURE), 'flows.0.phases.1.worldTurn.0', ['key' => 'street', 'kind' => 'shop', 'title' => 'A street vendor', 'offer' => ['gear' => ['katana']]]);

        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains('flows[0].phases[1].worldTurn[0].offer.gear[0]: unknown gear "katana"');

        ReleaseContent::fromArray($release);
    }
}
