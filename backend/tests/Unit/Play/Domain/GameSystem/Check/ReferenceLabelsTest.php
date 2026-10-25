<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\GameSystem\Check;

use App\Formula\Domain\Formula;
use App\Play\Domain\GameSystem\Check\ReferenceLabels;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Play\Domain\GameSystem\Sheet\TrackField;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReferenceLabels::class)]
final class ReferenceLabelsTest extends TestCase
{
    #[Test]
    public function aFormulaIsSpelledInTheLabelsPlayersKnow(): void
    {
        $labels = new ReferenceLabels(new SheetTemplate([new NumberField('agility', 'Agility'), new NumberField('physical', 'Physical'), new TrackField('hp', 'Health', max: 6)]), ['chaos' => 'Chaos factor']);

        self::assertSame('Agility + Health max - Physical * 2 + Chaos factor', $labels->spell(Formula::fromString('{agility} + {hp.max} - {physical} * 2 + {tracker:chaos}')));
    }

    #[Test]
    public function aKeyTheReleaseDoesNotNameReadsAsItself(): void
    {
        self::assertSame('ghost + 1', new ReferenceLabels(new SheetTemplate([]))->spell(Formula::fromString('{ghost} + 1')));
    }
}
