<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\FlowRun\InvalidStepResult;
use App\Play\Domain\Campaign\FlowRun\PickCandidate;
use App\Play\Domain\Campaign\FlowRun\PickCandidates;
use App\Play\Domain\Campaign\FlowRun\PickedItem;
use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Play\Domain\GameSystem\Flow\PickMode;
use App\Play\Domain\GameSystem\Flow\PickStep;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PickCandidates::class)]
#[CoversClass(InvalidStepResult::class)]
final class PickCandidatesTest extends TestCase
{
    #[Test]
    public function aSelectionOfCandidatesWithinTheCountIsNamedByTheCandidates(): void
    {
        $selected = $this->candidates()->select($this->step(1, 2), ['bea', 'ana']);

        self::assertEquals([new PickedItem('bea', 'Bea'), new PickedItem('ana', 'Ana')], $selected);
    }

    #[Test]
    public function tooFewCandidatesSelectNothing(): void
    {
        self::assertSame([], $this->candidates()->select($this->step(4, 4), []));
    }

    /**
     * @param list<string> $ids
     */
    #[Test]
    #[DataProvider('refusedSelections')]
    public function aSelectionThatBreaksTheRulesIsRefusedWhole(int $min, int $max, array $ids, string $message): void
    {
        $this->expectException(InvalidStepResult::class);
        $this->expectExceptionMessageIsOrContains($message);

        $this->candidates()->select($this->step($min, $max), $ids);
    }

    /**
     * @return iterable<string, array{int, int, list<string>, string}>
     */
    public static function refusedSelections(): iterable
    {
        yield 'an id that is not a candidate' => [1, 2, ['ana', 'zed'], 'Pick step "who" has no candidate "zed".'];
        yield 'a repeated id' => [1, 2, ['ana', 'ana'], 'Pick step "who" was given "ana" twice.'];
        yield 'too few ids' => [2, 3, ['ana'], 'Pick step "who" takes 2 to 3 items, got 1.'];
        yield 'too many ids' => [1, 1, ['ana', 'bea'], 'Pick step "who" takes 1 item, got 2.'];
        yield 'none when enough candidates exist' => [1, 2, [], 'Pick step "who" takes 1 to 2 items, got 0.'];
        yield 'ids when too few candidates exist' => [4, 4, ['ana'], 'Pick step "who" needs 4 candidates, has 3: nothing can be picked.'];
    }

    private function candidates(): PickCandidates
    {
        return new PickCandidates([new PickCandidate('ana', 'Ana', 1), new PickCandidate('bea', 'Bea', 3), new PickCandidate('cid', 'Cid', 2)]);
    }

    private function step(int $min, int $max): PickStep
    {
        return new PickStep('who', 'Who?', null, null, false, null, [], ItemKind::Npc, [], PickMode::Choose, $min, $max);
    }
}
