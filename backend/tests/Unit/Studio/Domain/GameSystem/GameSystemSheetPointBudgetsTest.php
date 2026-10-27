<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\GameSystem;

use App\Studio\Domain\GameSystem\DraftReferences;
use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\GameSystemId;
use App\Studio\Domain\GameSystem\SheetFieldInUse;
use App\Studio\Domain\GameSystem\SheetPointBudgetNotFound;
use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Several point budgets on a draft (ADR 0043): put and delete a budget by key, the legacy single
 * budget as the key `points`, the in-use guards per budget, and every command all or nothing.
 */
#[CoversClass(GameSystem::class)]
#[CoversClass(DraftReferences::class)]
final class GameSystemSheetPointBudgetsTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-16T10:00:00');
    }

    /**
     * @param array<string, mixed> $budgets   "pointBudget" and/or "pointBudgets" of the sheet
     * @param array<string, mixed> $unchecked sheet properties stored without the release checks (a draft restored from a damaged document)
     */
    private function draft(array $budgets = [], array $unchecked = []): GameSystem
    {
        $sheet = ['fields' => [
            ['key' => 'str', 'label' => 'Strength', 'type' => 'number', 'min' => 1, 'max' => 5],
            ['key' => 'con', 'label' => 'Constitution', 'type' => 'number', 'min' => 1, 'max' => 5],
            ['key' => 'lore', 'label' => 'Lore', 'type' => 'number', 'min' => 0, 'max' => 5],
            ['key' => 'half', 'label' => 'Half', 'type' => 'derived', 'formula' => '{str}/2'],
        ]] + $budgets;
        $source = ReleaseContent::fromArray(ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-minimal'), 'sheet', $sheet))->toArray();
        $source['sheet'] = $sheet + $unchecked;

        return GameSystem::createFromRelease(GameSystemId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a71'), 'my-copy', 'My copy', null, 'user-1', $source, new \DateTimeImmutable('2026-10-15T10:00:00'));
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(): array
    {
        return ['label' => 'Attribute points', 'total' => 12, 'fields' => ['str', 'con']];
    }

    /**
     * @return array<string, mixed>
     */
    private function skills(): array
    {
        return ['label' => 'Skill points', 'total' => 8, 'fields' => ['lore'], 'max' => 3];
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

    #[Test]
    public function aBudgetIsPutByKeyAppendedThenReplacedInPlace(): void
    {
        $gameSystem = $this->draft();

        $gameSystem->saveSheetPointBudgetEntry('attributes', $this->attributes(), $this->now);
        $gameSystem->saveSheetPointBudgetEntry('skills', $this->skills(), $this->now);
        $gameSystem->saveSheetPointBudgetEntry('attributes', [...$this->attributes(), 'total' => 10], $this->now);

        $sheet = $this->sheetOf($gameSystem);
        self::assertArrayNotHasKey('pointBudget', $sheet);
        self::assertSame(
            [['key' => 'attributes', 'label' => 'Attribute points', 'total' => 10, 'fields' => ['str', 'con']], ['key' => 'skills', ...$this->skills()]],
            $sheet['pointBudgets'],
        );
    }

    #[Test]
    public function aOneEntryListIsValidInTheDraftAndPublishesAsTheLegacyBudget(): void
    {
        $gameSystem = $this->draft();
        $gameSystem->saveSheetPointBudgetEntry('attributes', $this->attributes(), $this->now);

        self::assertCount(1, (array) $this->sheetOf($gameSystem)['pointBudgets']);
        $published = ReleaseContent::fromArray($gameSystem->document())->toArray();
        self::assertSame($this->attributes(), ((array) $published['sheet'])['pointBudget']);
    }

    #[Test]
    public function aLegacyBudgetIsTheKeyPointsAndAKeyedWriteMovesItToTheList(): void
    {
        $gameSystem = $this->draft(['pointBudget' => $this->attributes()]);

        $gameSystem->saveSheetPointBudgetEntry('points', [...$this->attributes(), 'total' => 9], $this->now);

        $sheet = $this->sheetOf($gameSystem);
        self::assertArrayNotHasKey('pointBudget', $sheet);
        self::assertSame([['key' => 'points', ...$this->attributes(), 'total' => 9]], $sheet['pointBudgets']);
    }

    #[Test]
    public function aLabelLessLegacyBudgetStaysAsRestoredUntilAKeyedWrite(): void
    {
        $gameSystem = $this->draft(['pointBudget' => ['total' => 12, 'fields' => ['str', 'con']]]);
        $before = $gameSystem->document();

        try {
            $gameSystem->saveSheetPointBudgetEntry('skills', $this->skills(), $this->now);
            self::fail('Expected InvalidReleaseContent');
        } catch (InvalidReleaseContent $invalid) {
            self::assertStringStartsWith('sheet.pointBudgets[0].label', $invalid->getMessage());
        }

        self::assertSame($before, $gameSystem->document());
        $gameSystem->saveSheetPointBudgetEntry('points', $this->attributes(), $this->now);
        $gameSystem->saveSheetPointBudgetEntry('skills', $this->skills(), $this->now);
        self::assertCount(2, (array) $this->sheetOf($gameSystem)['pointBudgets']);
    }

    #[Test]
    public function aBudgetIsDeletedByKeyAndTheLastOneRemovesTheProperty(): void
    {
        $gameSystem = $this->draft(['pointBudgets' => [['key' => 'attributes', ...$this->attributes()], ['key' => 'skills', ...$this->skills()]]]);

        $gameSystem->removeSheetPointBudgetEntry('attributes', $this->now);
        self::assertSame([['key' => 'skills', ...$this->skills()]], $this->sheetOf($gameSystem)['pointBudgets']);

        $gameSystem->removeSheetPointBudgetEntry('skills', $this->now);
        self::assertArrayNotHasKey('pointBudgets', $this->sheetOf($gameSystem));
        self::assertArrayNotHasKey('pointBudget', $this->sheetOf($gameSystem));
    }

    #[Test]
    public function aLegacyBudgetIsDeletedAsPoints(): void
    {
        $gameSystem = $this->draft(['pointBudget' => $this->attributes()]);

        $gameSystem->removeSheetPointBudgetEntry('points', $this->now);

        self::assertArrayNotHasKey('pointBudget', $this->sheetOf($gameSystem));
    }

    #[Test]
    public function deletingAnUnknownBudgetIsNotFoundAndChangesNothing(): void
    {
        $gameSystem = $this->draft(['pointBudgets' => [['key' => 'attributes', ...$this->attributes()]]]);
        $before = $gameSystem->document();

        $this->expectException(SheetPointBudgetNotFound::class);
        try {
            $gameSystem->removeSheetPointBudgetEntry('skills', $this->now);
        } finally {
            self::assertSame($before, $gameSystem->document());
        }
    }

    #[Test]
    public function aFieldInAnotherBudgetIsRefusedAtTheSavedBudgetsFieldPathAndChangesNothing(): void
    {
        $gameSystem = $this->draft(['pointBudgets' => [['key' => 'attributes', ...$this->attributes()]]]);
        $before = $gameSystem->document();

        try {
            $gameSystem->saveSheetPointBudgetEntry('skills', [...$this->skills(), 'fields' => ['lore', 'con']], $this->now);
            self::fail('Expected InvalidReleaseContent');
        } catch (InvalidReleaseContent $invalid) {
            self::assertStringStartsWith('sheet.pointBudgets[1].fields[1]: field "con" is already in budget "attributes"', $invalid->getMessage());
        }

        self::assertSame($before, $gameSystem->document());
    }

    #[Test]
    public function anInvalidKeyIsRefusedAtTheBudgetKeyPath(): void
    {
        $gameSystem = $this->draft();

        try {
            $gameSystem->saveSheetPointBudgetEntry('Bad Key', $this->attributes(), $this->now);
            self::fail('Expected InvalidReleaseContent');
        } catch (InvalidReleaseContent $invalid) {
            self::assertStringStartsWith('sheet.pointBudgets[0].key', $invalid->getMessage());
        }
    }

    #[Test]
    public function aMalformedStoredListIsRefusedNotAssumed(): void
    {
        $gameSystem = $this->draft(unchecked: ['pointBudgets' => ['not a list' => 1]]);

        try {
            $gameSystem->saveSheetPointBudgetEntry('skills', $this->skills(), $this->now);
            self::fail('Expected InvalidReleaseContent');
        } catch (InvalidReleaseContent $invalid) {
            self::assertSame('sheet.pointBudgets: must be a list', $invalid->getMessage());
        }
    }

    #[Test]
    public function aFieldOfAnyBudgetCannotBeDeletedOrRenamedAndTheErrorNamesItsPath(): void
    {
        $gameSystem = $this->draft(['pointBudgets' => [['key' => 'attributes', ...$this->attributes()], ['key' => 'skills', ...$this->skills()]]]);
        $before = $gameSystem->document();

        try {
            $gameSystem->deleteSheetField('lore', $this->now);
            self::fail('Expected SheetFieldInUse');
        } catch (SheetFieldInUse $inUse) {
            self::assertSame(['sheet.pointBudgets[1].fields[0]'], $inUse->paths());
        }

        try {
            $gameSystem->saveSheetField('con', ['key' => 'body', 'label' => 'Body', 'type' => 'number'], $this->now);
            self::fail('Expected SheetFieldInUse');
        } catch (SheetFieldInUse $inUse) {
            self::assertSame(['sheet.pointBudgets[0].fields[1]'], $inUse->paths());
        }

        self::assertSame($before, $gameSystem->document());
    }

    #[Test]
    public function aBudgetFieldCannotChangeToAnotherTypeInAnyBudget(): void
    {
        $gameSystem = $this->draft(['pointBudgets' => [['key' => 'attributes', ...$this->attributes()], ['key' => 'skills', ...$this->skills()]]]);

        try {
            $gameSystem->saveSheetField('lore', ['key' => 'lore', 'label' => 'Lore', 'type' => 'text'], $this->now);
            self::fail('Expected InvalidReleaseContent');
        } catch (InvalidReleaseContent $invalid) {
            self::assertStringStartsWith('sheet.pointBudgets[1].fields[0]: field "lore" is a text field', $invalid->getMessage());
        }
    }
}
