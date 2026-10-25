<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\GameSystem\Check;

use App\Formula\Domain\Formula;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\GameSystem\Check\Check;
use App\Play\Domain\GameSystem\Check\CheckBand;
use App\Play\Domain\GameSystem\Check\CheckDifficulty;
use App\Play\Domain\GameSystem\Check\CheckEvaluationFailed;
use App\Play\Domain\GameSystem\Check\CheckNotPushable;
use App\Play\Domain\GameSystem\Check\CheckPool;
use App\Play\Domain\GameSystem\Check\CheckPush;
use App\Play\Domain\GameSystem\Check\PushResult;
use App\Play\Domain\GameSystem\Flow\SheetEffect;
use App\Play\Domain\GameSystem\Flow\SheetOperation;
use App\Play\Domain\GameSystem\Flow\TrackerEffect;
use App\Play\Domain\GameSystem\Flow\TrackerOperation;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Randomness\Domain\DiceExpression;
use App\Randomness\Domain\DicePool;
use App\Randomness\Domain\PoolRoll;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pushing a pool check (ADR 0041): the pool "grit" is 4d6 succeeding on 5+, with the bands miss (0),
 * weak (up to 2) and strong, and a push that costs a point of "stress".
 */
#[CoversClass(Check::class)]
#[CoversClass(CheckPush::class)]
#[CoversClass(PushResult::class)]
#[CoversClass(CheckNotPushable::class)]
final class CheckPushTest extends TestCase
{
    #[Test]
    public function aPushRerollsTheFailedDiceAndPicksTheBandAgain(): void
    {
        $roll = PoolRoll::ofFaces(new DicePool(4, 6, 5), [1, 6, 2, 3]);

        $result = $this->grit()->push(new ScriptedRandomNumberGenerator(5, 6, 1), $this->sheet(), $roll, null);

        self::assertSame(3, $result->total);
        self::assertSame(3, $result->roll->successes());
        self::assertSame('strong', $result->band->key);
        self::assertSame([5, 6, 6, 1], array_map(static fn (\App\Randomness\Domain\PoolDie $die): int => $die->face(), $result->roll->dice()));
    }

    #[Test]
    public function aPushThatStillFailsStaysInTheBandItEarned(): void
    {
        $roll = PoolRoll::ofFaces(new DicePool(4, 6, 5), [1, 1, 1, 1]);

        $result = $this->grit()->push(new ScriptedRandomNumberGenerator(2, 2, 2, 2), $this->sheet(), $roll, null);

        self::assertSame([0, 'miss'], [$result->total, $result->band->key]);
    }

    #[Test]
    public function theBandBoundsAreReadFromTheCurrentSheetAndTheRollsDifficulty(): void
    {
        $check = new Check('grit', 'Grit', null, null, null, new CheckDifficulty('Difficulty', 2, 1, 4), [new CheckBand('fail', 'Fail', Formula::fromString('{check:difficulty} - 1')), new CheckBand('pass', 'Pass')], new CheckPool(2, 6, 5), new CheckPush('Take stress'));
        $roll = PoolRoll::ofFaces(new DicePool(2, 6, 5), [1, 5]);

        $one = $check->push(new ScriptedRandomNumberGenerator(1), $this->sheet(), $roll, 2);
        $other = $check->push(new ScriptedRandomNumberGenerator(1), $this->sheet(), $roll, 1);

        self::assertSame(['fail', 'pass'], [$one->band->key, $other->band->key]);
        self::assertSame(1, $one->total);
    }

    #[Test]
    public function aCheckThatCannotBeEvaluatedRerollsNothing(): void
    {
        $check = new Check('grit', 'Grit', null, null, null, null, [new CheckBand('fail', 'Fail', Formula::fromString('{stress} / {zero}')), new CheckBand('pass', 'Pass')], new CheckPool(2, 6, 5), new CheckPush('Take stress'));
        $random = new ScriptedRandomNumberGenerator();

        $this->expectException(CheckEvaluationFailed::class);

        // The empty script would fail with a LogicException if a die were re-rolled.
        $check->push($random, $this->sheet(), PoolRoll::ofFaces(new DicePool(2, 6, 5), [1, 5]), null);
    }

    #[Test]
    public function aCheckWithoutAPushCannotBePushed(): void
    {
        $check = new Check('grit', 'Grit', null, null, null, null, [new CheckBand('only', 'Only')], new CheckPool(2, 6, 5));

        $this->expectException(CheckNotPushable::class);
        $this->expectExceptionMessageIsOrContains('declares no push');

        $check->push(new ScriptedRandomNumberGenerator(1), $this->sheet(), PoolRoll::ofFaces(new DicePool(2, 6, 5), [1, 5]), null);
    }

    #[Test]
    public function aRollWithoutAFailedDieCannotBePushed(): void
    {
        $this->expectException(CheckNotPushable::class);
        $this->expectExceptionMessageIsOrContains('every die of the roll succeeded');

        $this->grit()->push(new ScriptedRandomNumberGenerator(), $this->sheet(), PoolRoll::ofFaces(new DicePool(2, 6, 5), [5, 6]), null);
    }

    #[Test]
    public function aPushNeedsAPool(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('declares a push without rolling a pool');

        new Check('grit', 'Grit', null, DiceExpression::fromString('1d6'), null, null, [new CheckBand('only', 'Only')], null, new CheckPush('Take stress'));
    }

    #[Test]
    public function theCostListsTheFieldOfEachMarkWithoutAnOptionInOrder(): void
    {
        $push = new CheckPush('Take a Condition', [
            new SheetEffect('stress', SheetOperation::Add, 1),
            new SheetEffect('physical', SheetOperation::Mark),
            new SheetEffect('physical', SheetOperation::Mark, 'hurt'),
            new TrackerEffect('doom', TrackerOperation::Add, 1),
            new SheetEffect('mental', SheetOperation::Mark),
        ]);

        self::assertSame(['physical', 'mental'], $push->picks());
        self::assertSame([], new CheckPush('Nothing')->picks());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function badPushes(): iterable
    {
        yield 'a blank label' => [' ', 0];
        yield 'a label over 100 characters' => [str_repeat('a', 101), 0];
        yield 'more than ten effects' => ['Cost', 11];
    }

    #[Test]
    #[DataProvider('badPushes')]
    public function aPushWithABadLabelOrTooManyEffectsIsRefused(string $label, int $effects): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CheckPush($label, array_fill(0, $effects, new SheetEffect('stress', SheetOperation::Add, 1)));
    }

    private function grit(): Check
    {
        return new Check('grit', 'Grit', null, null, null, null, [new CheckBand('miss', 'Miss', 0), new CheckBand('weak', 'Weak', 2), new CheckBand('strong', 'Strong')], new CheckPool(4, 6, 5), new CheckPush('Take a point of stress', [new SheetEffect('stress', SheetOperation::Add, 1)]));
    }

    private function sheet(): SheetValues
    {
        return SheetValues::of(new SheetTemplate([new NumberField('stress', 'Stress', default: 1), new NumberField('zero', 'Zero')]), [], []);
    }
}
