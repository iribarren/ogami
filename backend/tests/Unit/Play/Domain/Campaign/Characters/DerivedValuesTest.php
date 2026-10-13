<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\Characters;

use App\Formula\Domain\Formula;
use App\Play\Domain\Campaign\Characters\DerivedValues;
use App\Play\Domain\GameSystem\Sheet\DerivedField;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Play\Domain\GameSystem\Sheet\TextField;
use App\Play\Domain\GameSystem\Sheet\TrackField;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The DerivedValues of a Character (ADR 0023), on a sheet of reflexes (default 4), luck, hp (a track
 * with a fixed max of 40) and a role, whose derived fields are listed before the ones they read.
 */
#[CoversClass(DerivedValues::class)]
final class DerivedValuesTest extends TestCase
{
    #[Test]
    public function itComputesEachDerivedFieldInSheetOrderAfterTheFieldsItReads(): void
    {
        $derived = DerivedValues::of($this->sheet(['guard' => '{dodge} + 1', 'dodge' => '{reflexes} * 2 + {luck}']), ['reflexes' => 7, 'luck' => -2], []);

        self::assertSame(['guard' => 13, 'dodge' => 12], $derived);
    }

    #[Test]
    public function aFieldTheCharacterHoldsNoValueForReadsAsItsDefault(): void
    {
        $derived = DerivedValues::of($this->sheet(['dodge' => '{reflexes} * 2 + {luck}', 'life' => '{hp} + {hp.max}']), [], []);

        self::assertSame(['dodge' => 8, 'life' => 80], $derived, 'reflexes 4, luck 0, hp 40 of 40');
    }

    #[Test]
    public function aTrackReadsAsItsCurrentAndItsMaxAsDotMax(): void
    {
        $derived = DerivedValues::of($this->sheet(['life' => '{hp}', 'ceiling' => '{hp.max}', 'ratio' => '{hp} * 100 / {hp.max}']), ['hp' => ['current' => 10, 'max' => 40]], []);

        self::assertSame(['life' => 10, 'ceiling' => 40, 'ratio' => 25], $derived);
    }

    #[Test]
    public function itReadsTheCampaignsTrackerValues(): void
    {
        $derived = DerivedValues::of($this->sheet(['edge' => '{reflexes} + {tracker:chaos}']), ['reflexes' => 3], ['chaos' => 6, 'heat' => 1]);

        self::assertSame(['edge' => 9], $derived);
    }

    #[Test]
    public function aFormulaThatFailsIsNullAndSoIsEveryFormulaThatReadsIt(): void
    {
        $derived = DerivedValues::of(
            $this->sheet(['ratio' => '{reflexes} / {luck}', 'next' => '{ratio} + 1', 'later' => '{next} * 2', 'fine' => '{reflexes}']),
            ['reflexes' => 5, 'luck' => 0],
            [],
        );

        self::assertSame(['ratio' => null, 'next' => null, 'later' => null, 'fine' => 5], $derived);
    }

    #[Test]
    public function anOverflowIsNull(): void
    {
        $derived = DerivedValues::of($this->sheet(['huge' => '{reflexes} * 1000000 * 1000']), ['reflexes' => 10], []);

        self::assertSame(['huge' => null], $derived);
    }

    #[Test]
    public function aReferenceWithNoValueIsNullInsteadOfAnError(): void
    {
        $derived = DerivedValues::of($this->sheet(['edge' => '{tracker:chaos}', 'title' => '{role}']), [], []);

        self::assertSame(['edge' => null, 'title' => null], $derived, 'No such Tracker value, and a text field has none.');
    }

    #[Test]
    public function aCheckReferenceNeverReadsASheetField(): void
    {
        // Published content cannot hold one; if it ever did, it must not resolve to the field of the same key.
        $derived = DerivedValues::of($this->sheet(['edge' => '{check:reflexes}']), ['reflexes' => 5], []);

        self::assertSame(['edge' => null], $derived);
    }

    #[Test]
    public function aSheetWithoutDerivedFieldsHasNoDerivedValues(): void
    {
        self::assertSame([], DerivedValues::of($this->sheet([]), ['reflexes' => 3], []));
    }

    /**
     * @param array<string, string> $formulas derived field key to formula, in sheet order
     */
    private function sheet(array $formulas): SheetTemplate
    {
        $derived = [];
        foreach ($formulas as $key => $formula) {
            $derived[] = new DerivedField($key, ucfirst($key), null, null, Formula::fromString($formula));
        }

        return new SheetTemplate([
            new TextField('role', 'Role'),
            new NumberField('reflexes', 'Reflexes', default: 4),
            new NumberField('luck', 'Luck'),
            new TrackField('hp', 'HP', max: 40),
            ...$derived,
        ]);
    }
}
