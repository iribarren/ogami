<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\FlowRun\SheetChange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SheetChange::class)]
final class SheetChangeTest extends TestCase
{
    #[Test]
    public function aChangeThatHeldTheValueAndRolledNoDiceIsANoOp(): void
    {
        self::assertTrue(new SheetChange('hp', 7, 7)->isNoOp());
        self::assertFalse(new SheetChange('hp', 7, 8)->isNoOp());
        // The dice tell why nothing changed.
        self::assertFalse(new SheetChange('hp', 7, 7, '1d8', 4)->isNoOp());
    }

    #[Test]
    public function withoutNoOpsKeepsTheOtherChangesInOrder(): void
    {
        $changed = new SheetChange('sanity', 65, 61, '1d6', 4);
        $rolled = new SheetChange('hp', 7, 7, '1d8', 4);

        self::assertSame([$changed, $rolled], SheetChange::withoutNoOps([new SheetChange('hp', 7, 7), $changed, new SheetChange('pow', 3, 3), $rolled]));
        self::assertSame([], SheetChange::withoutNoOps([new SheetChange('hp', 7, 7)]));
    }
}
