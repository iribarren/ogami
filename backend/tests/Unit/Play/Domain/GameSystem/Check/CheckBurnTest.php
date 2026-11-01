<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\GameSystem\Check;

use App\Formula\Domain\Formula;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\GameSystem\Check\BurnResult;
use App\Play\Domain\GameSystem\Check\Check;
use App\Play\Domain\GameSystem\Check\CheckBand;
use App\Play\Domain\GameSystem\Check\CheckChallenge;
use App\Play\Domain\GameSystem\Check\CheckEvaluationFailed;
use App\Play\Domain\GameSystem\Check\CheckNotPushable;
use App\Play\Domain\GameSystem\Check\CheckPush;
use App\Play\Domain\GameSystem\Check\SpecialResult;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Randomness\Domain\ChallengeDice;
use App\Randomness\Domain\ChallengeRoll;
use App\Randomness\Domain\DiceExpression;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Burning momentum on a challenge check (ADR 0049): no dice are rolled, the score becomes the momentum (capped at
 * the score cap), and the burn is offered only while it beats more challenge dice than the score the roll had.
 * An action roll is 1d6 + {edge} against 2d10, capped at 10, with a push that burns {momentum} (-6..10).
 */
#[CoversClass(Check::class)]
#[CoversClass(BurnResult::class)]
#[CoversClass(CheckNotPushable::class)]
final class CheckBurnTest extends TestCase
{
    /**
     * @return iterable<string, array{list<int>, int, int, int, string}> the challenge dice, the first score, the momentum, the dice beaten after the burn and the band
     */
    public static function improvingBurns(): iterable
    {
        yield 'momentum beats both dice' => [[3, 9], 6, 10, 2, 'strong'];
        yield 'momentum beats the one die that the score missed' => [[3, 7], 6, 8, 2, 'strong'];
        yield 'a miss turned into a weak hit' => [[7, 9], 2, 8, 1, 'weak'];
        yield 'a miss turned into a strong hit' => [[7, 9], 2, 10, 2, 'strong'];
    }

    /**
     * @param list<int> $faces
     */
    #[Test]
    #[DataProvider('improvingBurns')]
    public function theScoreBecomesTheMomentumAndTheDiceStayAsRolled(array $faces, int $firstScore, int $momentum, int $total, string $band): void
    {
        $result = $this->action()->burn($this->sheet(['momentum' => $momentum]), $this->roll($faces), $firstScore);

        self::assertSame([$momentum, $momentum, $total, $band, null], [$result->momentum, $result->score, $result->total, $result->band->key, $result->special]);
    }

    /**
     * @return iterable<string, array{list<int>, int, int}> the challenge dice, the first score and the momentum
     */
    public static function burnsThatImproveNothing(): iterable
    {
        yield 'momentum below the score' => [[3, 9], 6, 4];
        yield 'momentum equal to the score' => [[3, 9], 6, 6];
        yield 'momentum above the score that beats no more dice' => [[3, 9], 6, 9];
        yield 'momentum equal to a die does not beat it' => [[3, 7], 6, 7];
        yield 'zero momentum' => [[3, 9], 2, 0];
        yield 'negative momentum' => [[3, 9], 2, -3];
        yield 'a score that already beats every die' => [[3, 4], 6, 10];
    }

    /**
     * @param list<int> $faces
     */
    #[Test]
    #[DataProvider('burnsThatImproveNothing')]
    public function aBurnThatBeatsNoMoreDiceIsRefused(array $faces, int $firstScore, int $momentum): void
    {
        $check = $this->action();

        self::assertFalse($check->burnImproves($this->roll($faces), $firstScore, $momentum));
        $this->expectException(CheckNotPushable::class);

        $check->burn($this->sheet(['momentum' => $momentum]), $this->roll($faces), $firstScore);
    }

    #[Test]
    public function aMomentumAboveTheScoreCapIsCapped(): void
    {
        $check = new Check('move', 'Move', null, DiceExpression::fromString('1d6'), null, null, $this->bands(), null, new CheckPush('Burn', []), new CheckChallenge(2, 10, 8, 'momentum'));

        $result = $check->burn($this->sheet(['momentum' => 10]), $this->roll([3, 7]), 6);

        self::assertSame([10, 8, 2], [$result->momentum, $result->score, $result->total], 'The momentum read is the sheet value; the score is capped.');
        self::assertFalse($check->burnImproves($this->roll([3, 9]), 6, 10), 'The cap of 8 beats the 3 only, as the score did.');
    }

    #[Test]
    public function aMatchStaysAMatchThroughTheBurn(): void
    {
        $result = $this->action()->burn($this->sheet(['momentum' => 8]), $this->roll([5, 5]), 4);

        self::assertSame([2, SpecialResult::Match], [$result->total, $result->special]);
    }

    #[Test]
    public function theBurnReadsTheMomentumOfTheSheetNow(): void
    {
        $check = $this->action();
        $roll = $this->roll([3, 9]);

        self::assertSame(2, $check->burn($this->sheet(['momentum' => 10]), $roll, 6)->total);
        $this->expectException(CheckNotPushable::class);

        $check->burn($this->sheet(['momentum' => 4]), $roll, 6);
    }

    #[Test]
    public function aCheckWithoutAPushCannotBeBurned(): void
    {
        $check = new Check('move', 'Move', null, DiceExpression::fromString('1d6'), null, null, $this->bands(), null, null, new CheckChallenge(2, 10, 10, 'momentum'));

        self::assertFalse($check->burnImproves($this->roll([3, 9]), 2, 10));
        $this->expectException(CheckNotPushable::class);

        $check->burn($this->sheet(['momentum' => 10]), $this->roll([3, 9]), 2);
    }

    #[Test]
    public function aMomentumFieldTheSheetLacksFailsTheBurn(): void
    {
        $sheet = SheetValues::of(new SheetTemplate([new NumberField('edge', 'Edge', default: 2)]), [], []);

        $this->expectException(CheckEvaluationFailed::class);

        $this->action()->burn($sheet, $this->roll([3, 9]), 2);
    }

    #[Test]
    public function aBandBoundThatCannotBeEvaluatedFailsTheBurn(): void
    {
        $bands = [new CheckBand('miss', 'Miss', Formula::fromString('{ghost}')), new CheckBand('hit', 'Hit')];
        $check = new Check('move', 'Move', null, DiceExpression::fromString('1d6'), null, null, $bands, null, new CheckPush('Burn', []), new CheckChallenge(2, 10, 10, 'momentum'));

        $this->expectException(CheckEvaluationFailed::class);

        $check->burn($this->sheet(['momentum' => 10]), $this->roll([3, 9]), 2);
    }

    private function action(): Check
    {
        return new Check('move', 'Move', null, DiceExpression::fromString('1d6'), Formula::fromString('{edge}'), null, $this->bands(), null, new CheckPush('Burn', []), new CheckChallenge(2, 10, 10, 'momentum'));
    }

    /**
     * @param list<int> $faces
     */
    private function roll(array $faces): ChallengeRoll
    {
        return ChallengeRoll::ofFaces(new ChallengeDice(2, 10), $faces);
    }

    /**
     * @return list<CheckBand>
     */
    private function bands(): array
    {
        return [new CheckBand('miss', 'Miss', 0), new CheckBand('weak', 'Weak', 1), new CheckBand('strong', 'Strong')];
    }

    /**
     * @param array<string, mixed> $values
     */
    private function sheet(array $values = []): SheetValues
    {
        return SheetValues::of(new SheetTemplate([
            new NumberField('edge', 'Edge', default: 2),
            new NumberField('momentum', 'Momentum', min: -6, max: 10, default: 2),
        ]), $values, []);
    }
}
