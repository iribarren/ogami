<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\GameSystem;

use App\Studio\Domain\GameSystem\DraftReferences;
use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\GameSystemId;
use App\Studio\Domain\GameSystem\SheetFieldInUse;
use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The creation properties of a sheet (ADR 0040) keep a draft consistent: a field a start max
 * formula reads or the point budget lists cannot be deleted or renamed, and a budget field cannot
 * stop being a number.
 */
#[CoversClass(GameSystem::class)]
#[CoversClass(DraftReferences::class)]
final class GameSystemSheetCreationTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-16T10:00:00');
    }

    /**
     * str and con are in the budget; half reads both and hp starts from half, so con is also read
     * through a derived field; luck is free.
     */
    private function draft(): GameSystem
    {
        $sheet = ['fields' => [
            ['key' => 'str', 'label' => 'Strength', 'type' => 'number', 'min' => 1, 'max' => 99, 'roll' => ['dice' => '3d6*5']],
            ['key' => 'con', 'label' => 'Constitution', 'type' => 'number', 'min' => 1, 'max' => 99],
            ['key' => 'luck', 'label' => 'Luck', 'type' => 'number'],
            ['key' => 'half', 'label' => 'Half', 'type' => 'derived', 'formula' => '{con}/2'],
            ['key' => 'hp', 'label' => 'Hit points', 'type' => 'track', 'startMax' => ['formula' => '{luck}+{half}']],
        ], 'pointBudget' => ['total' => 200, 'fields' => ['str', 'con']]];
        $source = ReleaseContent::fromArray(ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-minimal'), 'sheet', $sheet))->toArray();

        return GameSystem::createFromRelease(GameSystemId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a71'), 'my-copy', 'My copy', null, 'user-1', $source, new \DateTimeImmutable('2026-10-15T10:00:00'));
    }

    #[Test]
    public function aFieldReadByAStartMaxFormulaCannotBeDeletedOrRenamed(): void
    {
        $gameSystem = $this->draft();
        $document = $gameSystem->document();

        try {
            $gameSystem->deleteSheetField('luck', $this->now);
            self::fail('Expected SheetFieldInUse');
        } catch (SheetFieldInUse $inUse) {
            self::assertSame(['sheet.fields[4].startMax.formula'], $inUse->paths());
        }

        try {
            $gameSystem->saveSheetField('half', ['key' => 'rest', 'label' => 'Half', 'type' => 'derived', 'formula' => '{con}/2'], $this->now);
            self::fail('Expected SheetFieldInUse');
        } catch (SheetFieldInUse $inUse) {
            self::assertSame(['sheet.fields[4].startMax.formula'], $inUse->paths());
        }

        self::assertSame($document, $gameSystem->document());
    }

    #[Test]
    public function aFieldListedInThePointBudgetCannotBeDeletedOrRenamed(): void
    {
        $gameSystem = $this->draft();
        $document = $gameSystem->document();

        try {
            $gameSystem->deleteSheetField('str', $this->now);
            self::fail('Expected SheetFieldInUse');
        } catch (SheetFieldInUse $inUse) {
            self::assertSame(['sheet.pointBudget.fields[0]'], $inUse->paths());
            self::assertSame('The sheet field "str" is used at sheet.pointBudget.fields[0].', $inUse->getMessage());
        }

        try {
            $gameSystem->saveSheetField('str', ['key' => 'strength', 'label' => 'Strength', 'type' => 'number'], $this->now);
            self::fail('Expected SheetFieldInUse');
        } catch (SheetFieldInUse $inUse) {
            self::assertSame(['sheet.pointBudget.fields[0]'], $inUse->paths());
        }

        self::assertSame($document, $gameSystem->document());
    }

    #[Test]
    public function aFieldOutsideTheBudgetAndStartFormulasStillDeletes(): void
    {
        $gameSystem = $this->draft();
        $gameSystem->saveSheetField(null, ['key' => 'spare', 'label' => 'Spare', 'type' => 'number'], $this->now);

        $gameSystem->deleteSheetField('spare', $this->now);

        /** @var array{fields: list<array<string, mixed>>} $sheet */
        $sheet = $gameSystem->document()['sheet'];
        self::assertNotContains('spare', array_column($sheet['fields'], 'key'));
    }

    #[Test]
    public function aBudgetFieldCannotChangeToAnotherType(): void
    {
        $gameSystem = $this->draft();
        $document = $gameSystem->document();

        try {
            $gameSystem->saveSheetField('str', ['key' => 'str', 'label' => 'Strength', 'type' => 'text'], $this->now);
            self::fail('Expected InvalidReleaseContent');
        } catch (InvalidReleaseContent $invalid) {
            self::assertStringContainsString('field "str" is a text field, a budget covers number fields', $invalid->getMessage());
        }

        self::assertSame($document, $gameSystem->document());
    }
}
