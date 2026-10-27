<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\Release;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The shapes of ADR 0045 in the schema version 2 file on their own: what the schema refuses
 * structurally, and the rules it leaves to the domain (ReleaseContentGearTest covers those), run
 * against examples/cpr-campaign-in-acts. The example is decoded with objects, so its empty bands
 * ({}) stay objects.
 */
#[CoversNothing]
final class ReleaseSchemaGearTest extends TestCase
{
    private const string SCHEMA_ID = 'https://ogami.app/contracts/gamesystem-release/v2.schema.json';
    private const string SCHEMA_FILE = __DIR__.'/../../../../../contracts/gamesystem-release/v2.schema.json';
    private const string FIXTURE = 'examples/cpr-campaign-in-acts';
    private const string SHOP = 'sceneTypes.20.play.0';

    #[Test]
    public function theExampleConforms(): void
    {
        self::assertTrue($this->conforms($this->changed('schemaVersion', 2)));
    }

    /**
     * @return iterable<string, array{string, mixed}> the path changed in the example and its new value; the schema refuses each
     */
    public static function structuralRefusals(): iterable
    {
        yield 'an entry without a cost' => ['gear.0', ['key' => 'used-pistol', 'name' => 'Used pistol']];
        yield 'an entry with an unknown property' => ['gear.0.weight', 2];
        yield 'a negative cost' => ['gear.0.cost', -1];
        yield 'slots above 100' => ['gear.0.slots', 101];
        yield 'a blank name' => ['gear.0.name', ' '];
        yield 'a category of 41 characters' => ['gear.0.category', str_repeat('a', 41)];
        yield 'a catalog of 501 entries' => ['gear', array_fill(0, 501, ['key' => 'g', 'name' => 'G', 'cost' => 1])];
        yield 'a currency without a field' => ['sheet.currency', ['unit' => 'eb']];
        yield 'a currency with an unknown property' => ['sheet.currency.symbol', '€'];
        yield 'a unit of 11 characters' => ['sheet.currency.unit', str_repeat('e', 11)];
        yield 'a capacity of zero' => ['sheet.fields.1.capacity', 0];
        yield 'maxStacks of 101' => ['sheet.fields.2.maxStacks', 101];
        yield 'an empty categories list' => ['sheet.fields.2.categories', []];
        yield 'a repeated category' => ['sheet.fields.1.categories', ['Gear', 'Gear']];
        yield 'a list property on an inventory' => ['sheet.fields.1.maxItems', 5];
        yield 'a shop without an offer' => [self::SHOP, ['key' => 'stalls', 'kind' => 'shop', 'title' => 'Shop']];
        yield 'an empty offer' => [self::SHOP.'.offer', ['gear' => [], 'categories' => null]];
        yield 'an offer with an unknown property' => [self::SHOP.'.offer.services', ['rent']];
        yield 'an offer repeating a key' => [self::SHOP.'.offer.gear', ['kibble', 'kibble']];
        yield 'a stock of zero' => [self::SHOP.'.stock', 0];
        yield 'a stock of 51' => [self::SHOP.'.stock', 51];
        yield 'a stock that is a string' => [self::SHOP.'.stock', '3'];
        yield 'a sell rate of 101' => [self::SHOP.'.sellRate', 101];
        yield 'a mandatory shop' => [self::SHOP.'.mandatory', true];
    }

    #[Test]
    #[DataProvider('structuralRefusals')]
    public function theSchemaRefusesAMalformedShape(string $path, mixed $value): void
    {
        self::assertFalse($this->conforms($this->changed($path, $value)));
    }

    /**
     * @return iterable<string, array{string, mixed}> what the schema accepts and the domain refuses
     */
    public static function domainRules(): iterable
    {
        yield 'a duplicate gear key' => ['gear.1.key', 'used-pistol'];
        yield 'a gear name repeated in another case' => ['gear.1.name', 'used PISTOL'];
        yield 'a currency of an unknown field' => ['sheet.currency.field', 'cash'];
        yield 'a currency on an inventory' => ['sheet.currency.field', 'gear'];
        yield 'a category no entry has' => ['sheet.fields.2.categories', ['Chrom']];
        yield 'an offer of an unknown key' => [self::SHOP.'.offer.gear', ['katana']];
        yield 'an offer of an unknown category' => [self::SHOP.'.offer.categories', ['chrome']];
        yield 'an offered entry no inventory accepts' => ['sheet.fields.2.categories', ['Food']];
        yield 'a stock above the offer' => [self::SHOP.'.stock', 9];
        yield 'a shop without a currency' => ['sheet.currency', null];
        yield 'a sheet effect on a shop' => [self::SHOP.'.effects', [['kind' => 'sheet', 'field' => 'eddies', 'op' => 'add', 'value' => 10]]];
    }

    #[Test]
    #[DataProvider('domainRules')]
    public function theSchemaLeavesTheseRulesToTheDomain(string $path, mixed $value): void
    {
        self::assertTrue($this->conforms($this->changed($path, $value)));
    }

    /**
     * The example with the value at a dotted path set, as JSON objects.
     */
    private function changed(string $path, mixed $value): \stdClass
    {
        $release = json_decode((string) file_get_contents(__DIR__.'/../../../../Fixtures/Studio/releases/'.self::FIXTURE.'.json'), flags: \JSON_THROW_ON_ERROR);
        \assert($release instanceof \stdClass);
        // Objects are handles, so walking a reference changes the decoded release in place.
        $root = $release;
        $node = &$release;
        $segments = explode('.', $path);
        $last = array_pop($segments);
        foreach ($segments as $segment) {
            if (\is_array($node)) {
                $node = &$node[(int) $segment];
            } else {
                \assert($node instanceof \stdClass);
                $node = &$node->{$segment};
            }
        }

        $value = json_decode(json_encode($value, \JSON_THROW_ON_ERROR), flags: \JSON_THROW_ON_ERROR);
        if (\is_array($node)) {
            $node[(int) $last] = $value;
        } else {
            \assert($node instanceof \stdClass);
            $node->{$last} = $value;
        }

        unset($node);

        return $root;
    }

    private function conforms(\stdClass $release): bool
    {
        $validator = new Validator();
        $validator->resolver()?->registerFile(self::SCHEMA_ID, self::SCHEMA_FILE);

        return $validator->validate($release, self::SCHEMA_ID)->isValid();
    }
}
