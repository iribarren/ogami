<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\GameSystem;

use App\Studio\Domain\GameSystem\DraftReferences;
use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\GameSystemId;
use App\Studio\Domain\GameSystem\GearInUse;
use App\Studio\Domain\GameSystem\GearNotFound;
use App\Studio\Domain\GameSystem\LibraryPackRefuses;
use App\Studio\Domain\GameSystem\SheetCurrencyNotFound;
use App\Studio\Domain\GameSystem\SheetFieldInUse;
use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The gear catalog and the sheet currency of a draft (ADR 0045): put and delete by key, the
 * publisher's rules as the judge of every write, the guards of a gear entry a shop step offers
 * and of the currency field, and every command all or nothing.
 */
#[CoversClass(GameSystem::class)]
#[CoversClass(DraftReferences::class)]
#[CoversClass(GearInUse::class)]
#[CoversClass(GearNotFound::class)]
#[CoversClass(SheetCurrencyNotFound::class)]
final class GameSystemGearTest extends TestCase
{
    private const string SHOP = 'sceneTypes.20.play.0';

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-16T10:00:00');
    }

    /**
     * @param array<mixed> $source a release document, a draft without gear or currency by default
     */
    private function draft(?array $source = null): GameSystem
    {
        $source = ReleaseContent::fromArray($source ?? ReleaseArrays::fixture('valid/v2-sheet'))->toArray();

        return GameSystem::createFromRelease(GameSystemId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a71'), 'my-copy', 'My copy', null, 'user-1', $source, new \DateTimeImmutable('2026-10-15T10:00:00'));
    }

    /**
     * The Night Market campaign: eight entries, the currency "eddies", two inventories and a shop
     * that offers its categories.
     */
    private function market(): GameSystem
    {
        return $this->draft(ReleaseArrays::fixture('examples/cpr-campaign-in-acts'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function gearOf(GameSystem $gameSystem): array
    {
        /** @var list<array<string, mixed>> $gear */
        $gear = $gameSystem->document()['gear'] ?? [];

        return $gear;
    }

    #[Test]
    public function anEntryIsPutByKeyAppendedThenReplacedInPlaceAndUnsetsWhatIsLeftOut(): void
    {
        $gameSystem = $this->draft();

        $gameSystem->saveGearEntry('rope', ['name' => 'Rope', 'cost' => 5, 'category' => 'Tools', 'description' => 'Fifty feet.'], $this->now);
        $gameSystem->saveGearEntry('torch', ['name' => 'Torch', 'cost' => 1, 'slots' => 2], $this->now);
        $gameSystem->saveGearEntry('rope', ['name' => 'Hemp rope', 'cost' => 6], $this->now);

        self::assertSame(
            [['key' => 'rope', 'name' => 'Hemp rope', 'cost' => 6], ['key' => 'torch', 'name' => 'Torch', 'cost' => 1, 'slots' => 2]],
            $this->gearOf($gameSystem),
        );
        self::assertEquals($this->now, $gameSystem->updatedAt());
    }

    #[Test]
    public function aKeyInTheEntryIsRefusedAndNothingChanges(): void
    {
        $gameSystem = $this->draft();

        try {
            $gameSystem->saveGearEntry('rope', ['key' => 'other', 'name' => 'Rope', 'cost' => 5], $this->now);
            self::fail('The entry carries a key.');
        } catch (InvalidReleaseContent $invalid) {
            self::assertStringStartsWith('gear[0].key', $invalid->getMessage());
        }

        self::assertArrayNotHasKey('gear', $gameSystem->document());
    }

    #[Test]
    public function thePublishersRulesRefuseAnEntryWithItsPathAndNothingChanges(): void
    {
        $gameSystem = $this->draft();
        $gameSystem->saveGearEntry('rope', ['name' => 'Rope', 'cost' => 5], $this->now);
        $before = $gameSystem->document();

        foreach ([
            ['gear[1].name', ['name' => ' rope ', 'cost' => 1]],
            ['gear[1].cost', ['name' => 'Torch', 'cost' => -1]],
            ['gear[1].slots', ['name' => 'Torch', 'cost' => 1, 'slots' => 101]],
            ['gear[1].name', ['cost' => 1]],
        ] as [$path, $entry]) {
            try {
                $gameSystem->saveGearEntry('torch', $entry, $this->now);
                self::fail(\sprintf('%s is refused.', $path));
            } catch (InvalidReleaseContent $invalid) {
                self::assertStringStartsWith($path, $invalid->getMessage());
            }
        }

        self::assertSame($before, $gameSystem->document());
    }

    #[Test]
    public function anEntryIsRemovedAndTheLastOneRemovesTheProperty(): void
    {
        $gameSystem = $this->draft();
        $gameSystem->saveGearEntry('rope', ['name' => 'Rope', 'cost' => 5], $this->now);
        $gameSystem->saveGearEntry('torch', ['name' => 'Torch', 'cost' => 1], $this->now);

        $gameSystem->removeGearEntry('rope', $this->now);
        self::assertSame([['key' => 'torch', 'name' => 'Torch', 'cost' => 1]], $this->gearOf($gameSystem));

        $gameSystem->removeGearEntry('torch', $this->now);
        self::assertArrayNotHasKey('gear', $gameSystem->document());
    }

    #[Test]
    public function removingAnEntryTheCatalogLacksIsNotFound(): void
    {
        $this->expectException(GearNotFound::class);

        $this->draft()->removeGearEntry('rope', $this->now);
    }

    #[Test]
    public function aShopThatOffersAnEntryRefusesItsRemovalAndListsWhereAndNothingChanges(): void
    {
        $release = ReleaseArrays::with(ReleaseArrays::fixture('examples/cpr-campaign-in-acts'), self::SHOP.'.offer', ['gear' => ['used-pistol'], 'categories' => ['Food', 'Chrome']]);
        $gameSystem = $this->draft($release);
        $before = $gameSystem->document();

        try {
            $gameSystem->removeGearEntry('used-pistol', $this->now);
            self::fail('The shop offers the entry.');
        } catch (GearInUse $inUse) {
            self::assertSame(['sceneTypes[20].play[0].offer.gear[0]'], $inUse->paths());
        }

        self::assertSame($before, $gameSystem->document());
        $gameSystem->removeGearEntry('armor-jacket', $this->now);
        self::assertCount(7, $this->gearOf($gameSystem));
    }

    #[Test]
    public function aFlowShopStepIsFoundToo(): void
    {
        $document = ['flows' => [['key' => 'night', 'phases' => [['sceneOpening' => [
            ['kind' => 'prompt'],
            ['kind' => 'shop', 'offer' => ['gear' => ['rope', 'torch']]],
        ]]]]]];

        self::assertSame(['flows[0].phases[0].sceneOpening[1].offer.gear[1]'], DraftReferences::gearPathsTo($document, 'torch'));
        self::assertSame([], DraftReferences::gearPathsTo($document, 'lamp'));
        self::assertSame([], DraftReferences::gearPathsTo([], 'torch'));
    }

    #[Test]
    public function aGearCategoryAnInventoryAcceptsCannotLoseItsLastEntryAndTheDraftKeepsItsEntries(): void
    {
        $gameSystem = $this->market();
        $before = $gameSystem->document();

        // "cyberware" accepts Chrome only: removing both Chrome entries leaves it with no gear.
        $gameSystem->removeGearEntry('cyberoptic', $this->now);
        try {
            $gameSystem->removeGearEntry('neural-link', $this->now);
            self::fail('The inventory accepts a category no entry has.');
        } catch (InvalidReleaseContent $invalid) {
            self::assertStringStartsWith('sheet.fields[2].categories[0]', $invalid->getMessage());
        }

        self::assertCount(7, $this->gearOf($gameSystem));
        self::assertNotSame($before, $gameSystem->document());
    }

    #[Test]
    public function gearIsRefusedOnALibraryPackAndOnASchemaVersion1Draft(): void
    {
        $pack = GameSystem::createLibraryPack(GameSystemId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a72'), 'names', 'user-1', 'Names', null, $this->now);
        foreach ([
            static fn () => $pack->saveGearEntry('rope', ['name' => 'Rope', 'cost' => 1], new \DateTimeImmutable()),
            static fn () => $pack->removeGearEntry('rope', new \DateTimeImmutable()),
            static fn () => $pack->saveSheetCurrency(['field' => 'gold'], new \DateTimeImmutable()),
            static fn () => $pack->removeSheetCurrency(new \DateTimeImmutable()),
        ] as $write) {
            try {
                $write();
                self::fail('A library pack has no gear or currency.');
            } catch (LibraryPackRefuses $refused) {
                self::assertStringContainsString('names', $refused->getMessage());
            }
        }

        $version1 = $this->draft(ReleaseArrays::fixture('valid/minimal'));
        foreach ([
            fn () => $version1->saveGearEntry('rope', ['name' => 'Rope', 'cost' => 1], $this->now),
            fn () => $version1->removeGearEntry('rope', $this->now),
            fn () => $version1->saveSheetCurrency(['field' => 'gold'], $this->now),
            fn () => $version1->removeSheetCurrency($this->now),
        ] as $write) {
            try {
                $write();
                self::fail('A version 1 draft has no sheet.');
            } catch (InvalidReleaseContent $invalid) {
                self::assertStringStartsWith('schemaVersion', $invalid->getMessage());
            }
        }
    }

    #[Test]
    public function theCurrencyIsPutAndReplacedAndUnsetsTheUnitLeftOut(): void
    {
        $gameSystem = $this->market();

        $gameSystem->saveSheetCurrency(['field' => 'eddies', 'unit' => 'ed'], $this->now);
        self::assertSame(['field' => 'eddies', 'unit' => 'ed'], $this->sheetOf($gameSystem)['currency']);

        $gameSystem->saveSheetCurrency(['field' => 'eddies'], $this->now);
        self::assertSame(['field' => 'eddies'], $this->sheetOf($gameSystem)['currency']);
    }

    #[Test]
    public function aCurrencyWithoutAShopIsRemovedAndNotFoundWhenThereIsNone(): void
    {
        $gameSystem = $this->draft(ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-sheet'), 'sheet.currency', ['field' => 'reflexes']));
        $gameSystem->removeSheetCurrency($this->now);
        self::assertArrayNotHasKey('currency', $this->sheetOf($gameSystem));

        $this->expectException(SheetCurrencyNotFound::class);
        $gameSystem->removeSheetCurrency($this->now);
    }

    #[Test]
    public function aCurrencyThatBreaksThePublishersRulesIsRefusedWithItsPath(): void
    {
        $gameSystem = $this->market();
        $before = $gameSystem->document();

        foreach ([
            'sheet.currency.field' => ['field' => 'gear'],
            'sheet.currency' => ['field' => 'eddies', 'color' => 'red'],
            'sheet.currency.unit' => ['field' => 'eddies', 'unit' => 'far too long a unit'],
        ] as $path => $currency) {
            try {
                $gameSystem->saveSheetCurrency($currency, $this->now);
                self::fail(\sprintf('%s is refused.', $path));
            } catch (InvalidReleaseContent $invalid) {
                self::assertStringStartsWith($path, $invalid->getMessage());
            }
        }

        self::assertSame($before, $gameSystem->document());
    }

    #[Test]
    public function theCurrencyOfAShopCannotBeRemoved(): void
    {
        $gameSystem = $this->market();

        try {
            $gameSystem->removeSheetCurrency($this->now);
            self::fail('A shop step needs the currency.');
        } catch (InvalidReleaseContent $invalid) {
            self::assertStringContainsString('sheet.currency', $invalid->getMessage());
        }

        self::assertSame(['field' => 'eddies', 'unit' => 'eb'], $this->sheetOf($gameSystem)['currency']);
    }

    #[Test]
    public function theCurrencyFieldCannotBeDeletedOrRenamed(): void
    {
        $gameSystem = $this->market();
        /** @var list<array<string, mixed>> $fields */
        $fields = $this->sheetOf($gameSystem)['fields'];
        $eddies = $fields[0];

        foreach ([
            fn () => $gameSystem->deleteSheetField('eddies', $this->now),
            fn () => $gameSystem->saveSheetField('eddies', [...$eddies, 'key' => 'gold'], $this->now),
        ] as $write) {
            try {
                $write();
                self::fail('The currency names the field.');
            } catch (SheetFieldInUse $inUse) {
                self::assertContains('sheet.currency.field', $inUse->paths());
            }
        }
    }

    #[Test]
    public function theCurrencyFieldCannotBeRetypedToAnotherType(): void
    {
        $gameSystem = $this->market();

        try {
            $gameSystem->saveSheetField('eddies', ['key' => 'eddies', 'label' => 'Eddies', 'type' => 'text'], $this->now);
            self::fail('The currency is a number field.');
        } catch (InvalidReleaseContent $invalid) {
            self::assertStringStartsWith('sheet.currency.field', $invalid->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function sheetOf(GameSystem $gameSystem): array
    {
        /** @var array<string, mixed> $sheet */
        $sheet = $gameSystem->document()['sheet'];

        return $sheet;
    }
}
