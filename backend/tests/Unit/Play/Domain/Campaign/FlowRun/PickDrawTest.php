<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\FlowRun\PickCandidate;
use App\Play\Domain\Campaign\FlowRun\PickCandidates;
use App\Play\Domain\Campaign\FlowRun\PickDraw;
use App\Play\Domain\Campaign\FlowRun\PickedItem;
use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Play\Domain\GameSystem\Flow\PickMode;
use App\Play\Domain\GameSystem\Flow\PickStep;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PickDraw::class)]
#[CoversClass(PickCandidates::class)]
#[CoversClass(PickCandidate::class)]
#[CoversClass(PickedItem::class)]
final class PickDrawTest extends TestCase
{
    #[Test]
    public function aDrawIsWeightedByEachItemsWeight(): void
    {
        // Weights 1, 3 and 2 make the ranges 1, 2-4 and 5-6: a 4 is Bea, a 1 is Ana, a 6 is Cid.
        foreach ([[1, 'ana'], [2, 'bea'], [4, 'bea'], [5, 'cid'], [6, 'cid']] as [$number, $id]) {
            $picked = new PickDraw(new ScriptedRandomNumberGenerator($number))->draw($this->step(1, 1), $this->candidates());

            self::assertSame([$id], array_map(static fn (PickedItem $item): string => $item->id, $picked), \sprintf('A %d draws %s.', $number, $id));
        }
    }

    #[Test]
    public function itemsAreDrawnWithoutReplacementAndKeepTheirNames(): void
    {
        // Bea first (4 of 6), then 3 of the remaining weight 3 (Ana 1, Cid 2) is Cid.
        $picked = new PickDraw(new ScriptedRandomNumberGenerator(4, 3))->draw($this->step(2, 2), $this->candidates());

        self::assertEquals([new PickedItem('bea', 'Bea'), new PickedItem('cid', 'Cid')], $picked);
    }

    #[Test]
    public function aDrawNeverExceedsTheMaximum(): void
    {
        $picked = new PickDraw(new ScriptedRandomNumberGenerator(1, 1))->draw($this->step(1, 2), $this->candidates());

        self::assertSame(['ana', 'bea'], array_map(static fn (PickedItem $item): string => $item->id, $picked));
    }

    #[Test]
    public function fewerCandidatesThanTheMaximumAreAllDrawn(): void
    {
        $picked = new PickDraw(new ScriptedRandomNumberGenerator(2, 1, 1))->draw($this->step(1, 5), $this->candidates());

        self::assertEqualsCanonicalizing(['ana', 'bea', 'cid'], array_map(static fn (PickedItem $item): string => $item->id, $picked));
    }

    #[Test]
    public function fewerCandidatesThanTheMinimumDrawNothingAndRollNothing(): void
    {
        $picked = new PickDraw(new ScriptedRandomNumberGenerator())->draw($this->step(4, 5), $this->candidates());

        self::assertSame([], $picked);
    }

    #[Test]
    public function noCandidatesDrawNothing(): void
    {
        self::assertSame([], new PickDraw(new ScriptedRandomNumberGenerator())->draw($this->step(1, 1), new PickCandidates([])));
    }

    private function candidates(): PickCandidates
    {
        return new PickCandidates([new PickCandidate('ana', 'Ana', 1), new PickCandidate('bea', 'Bea', 3), new PickCandidate('cid', 'Cid', 2)]);
    }

    private function step(int $min, int $max): PickStep
    {
        return new PickStep('who', 'Who?', null, null, false, null, [], ItemKind::Npc, [], PickMode::Random, $min, $max);
    }
}
