<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Formula\Domain\Formula;
use App\Play\Application\CharacterView;
use App\Play\Application\SheetFieldView;
use App\Play\Domain\Campaign\Characters\Character;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\GameSystem\Sheet\DerivedField;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Play\Domain\GameSystem\Sheet\TextField;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CharacterView::class)]
#[CoversClass(SheetFieldView::class)]
final class CharacterViewTest extends TestCase
{
    #[Test]
    public function aCharacterStoredBeforeTheSheetHadDerivedFieldsReadsWithItsDerivedValuesComputed(): void
    {
        // Stored when the sheet had a role and luck only: no derived key, and no value for "reflexes", added since.
        $legacy = Character::of(CharacterId::fromString('c-1'), 'Vi', [], [], ['role' => 'Solo', 'luck' => 3]);
        $sheet = new SheetTemplate([
            new TextField('role', 'Role'),
            new NumberField('reflexes', 'Reflexes', default: 4),
            new NumberField('luck', 'Luck'),
            new DerivedField('dodge', 'Dodge', null, null, Formula::fromString('{reflexes} * 2 + {luck} + {tracker:chaos}')),
        ]);

        $view = CharacterView::of($legacy, $sheet, ['chaos' => 5]);

        self::assertSame(['role' => 'Solo', 'luck' => 3], $view->values, 'The stored values are shown as they are, and no derived key.');
        self::assertSame(['dodge' => 16], $view->derived);
    }

    #[Test]
    public function aSheetWithoutDerivedFieldsGivesNoDerivedValues(): void
    {
        $character = Character::of(CharacterId::fromString('c-1'), 'Vi', [], [], ['luck' => 3]);

        self::assertSame([], CharacterView::of($character, new SheetTemplate([new NumberField('luck', 'Luck')]), [])->derived);
    }

    #[Test]
    public function aDerivedFieldViewCarriesItsFormulaAsWritten(): void
    {
        $view = SheetFieldView::of(new DerivedField('dodge', 'Dodge', 'Hard to hit', 'Stats', Formula::fromString('  {luck}*2 ')));

        self::assertSame(['dodge', 'derived', '{luck}*2', null, null], [$view->key, $view->type, $view->formula, $view->min, $view->maxLength]);
        self::assertNull(SheetFieldView::of(new NumberField('luck', 'Luck'))->formula);
    }
}
