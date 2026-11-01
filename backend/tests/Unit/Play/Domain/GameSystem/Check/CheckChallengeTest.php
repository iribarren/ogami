<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\GameSystem\Check;

use App\Formula\Domain\Formula;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\GameSystem\Check\ChallengeResult;
use App\Play\Domain\GameSystem\Check\Check;
use App\Play\Domain\GameSystem\Check\CheckBand;
use App\Play\Domain\GameSystem\Check\CheckChallenge;
use App\Play\Domain\GameSystem\Check\CheckDifficulty;
use App\Play\Domain\GameSystem\Check\CheckEvaluationFailed;
use App\Play\Domain\GameSystem\Check\CheckPool;
use App\Play\Domain\GameSystem\Check\CheckPush;
use App\Play\Domain\GameSystem\Check\SpecialResult;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Randomness\Domain\DiceExpression;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Performing a challenge check (ADR 0049) for a Character with edge 2, momentum 2 (-6..10) and progress 7:
 * an action roll is 1d6 + {edge} against 2d10 capped at 10, a progress roll is {progress} against 2d10. The
 * total is the challenge dice the score beats (ties go to the dice): up to 0 a miss, up to 1 weak, else strong.
 */
#[CoversClass(Check::class)]
#[CoversClass(CheckChallenge::class)]
#[CoversClass(ChallengeResult::class)]
final class CheckChallengeTest extends TestCase
{
    #[Test]
    public function anActionRollRollsTheActionDieThenTheChallengeDice(): void
    {
        $result = $this->action()->perform(new ScriptedRandomNumberGenerator(4, 3, 9), $this->sheet(), null, 0);

        self::assertSame(4, $result->roll?->total());
        self::assertInstanceOf(ChallengeResult::class, $result->challenge);
        self::assertSame([3, 9], $result->challenge->roll->faces());
        self::assertSame([6, 2, false], [$result->challenge->score, $result->challenge->momentum, $result->challenge->cancelled]);
        self::assertSame([2, 0, 1, 'weak', null], [$result->modifier, $result->situational, $result->total, $result->band->key, $result->special]);
        self::assertNull($result->pool);
    }

    #[Test]
    public function aTieGoesToTheDice(): void
    {
        $result = $this->action()->perform(new ScriptedRandomNumberGenerator(4, 6, 6), $this->sheet(), null, 0);

        self::assertSame([0, 'miss'], [$result->total, $result->band->key]);
    }

    #[Test]
    public function theSituationalModifierAddsToTheScore(): void
    {
        $result = $this->action()->perform(new ScriptedRandomNumberGenerator(4, 7, 3), $this->sheet(), null, 2);

        self::assertSame(8, $result->challenge?->score);
        self::assertSame([2, 'strong'], [$result->total, $result->band->key]);
    }

    #[Test]
    public function theScoreIsCappedAtScoreMax(): void
    {
        $result = $this->action()->perform(new ScriptedRandomNumberGenerator(6, 10, 10), $this->sheet(['edge' => 8]), null, 0);

        self::assertSame(10, $result->challenge?->score);
        self::assertSame([0, 'miss'], [$result->total, $result->band->key], 'A 10 does not beat a 10.');
    }

    #[Test]
    public function aScoreWithoutACapIsNotCapped(): void
    {
        $result = $this->progress()->perform(new ScriptedRandomNumberGenerator(1, 1), $this->sheet(['progress' => 400]), null, 0);

        self::assertSame(400, $result->challenge?->score);
    }

    /**
     * @return iterable<string, array{int, int, bool, int}> momentum, action die, cancelled, score
     */
    public static function momenta(): iterable
    {
        yield 'negative momentum equal to the die cancels it' => [-4, 4, true, 2];
        yield 'negative momentum different from the die' => [-4, 3, false, 5];
        yield 'positive momentum equal to the die' => [4, 4, false, 6];
        yield 'zero momentum on a die' => [0, 4, false, 6];
        yield 'momentum minus one on a one' => [-1, 1, true, 2];
    }

    #[Test]
    #[DataProvider('momenta')]
    public function negativeMomentumEqualToTheActionDieMakesItCountZero(int $momentum, int $die, bool $cancelled, int $score): void
    {
        $result = $this->action()->perform(new ScriptedRandomNumberGenerator($die, 5, 5), $this->sheet(['momentum' => $momentum]), null, 0);

        self::assertSame([$momentum, $cancelled, $score], [$result->challenge?->momentum, $result->challenge?->cancelled, $result->challenge?->score]);
        self::assertSame($die, $result->roll?->total(), 'The die keeps its face; the journal shows it.');
    }

    #[Test]
    public function aProgressRollHasNoActionDie(): void
    {
        $result = $this->progress()->perform(new ScriptedRandomNumberGenerator(3, 8), $this->sheet(), null, 0);

        self::assertNull($result->roll);
        self::assertInstanceOf(ChallengeResult::class, $result->challenge);
        self::assertSame([7, null, false], [$result->challenge->score, $result->challenge->momentum, $result->challenge->cancelled]);
        self::assertSame([7, 1, 'weak'], [$result->modifier, $result->total, $result->band->key]);
    }

    #[Test]
    public function aProgressRollIsCappedAtScoreMaxToo(): void
    {
        $result = $this->progress(5)->perform(new ScriptedRandomNumberGenerator(4, 5), $this->sheet(), null, 0);

        self::assertSame(5, $result->challenge?->score);
        self::assertSame(1, $result->total, '4 is beaten, 5 is not.');
    }

    #[Test]
    public function aMatchIsASpecialResultThatKeepsTheBand(): void
    {
        $result = $this->action()->perform(new ScriptedRandomNumberGenerator(4, 2, 2), $this->sheet(), null, 0);

        self::assertSame([SpecialResult::Match, 2, 'strong'], [$result->special, $result->total, $result->band->key]);
    }

    #[Test]
    public function aStepCannotNameAMatch(): void
    {
        self::assertFalse($this->action()->canProduce(SpecialResult::Match));
        self::assertFalse($this->progress()->canProduce(SpecialResult::Match));
    }

    #[Test]
    public function aMissingMomentumFieldRollsNothing(): void
    {
        $random = new ScriptedRandomNumberGenerator();
        $sheet = SheetValues::of(new SheetTemplate([new NumberField('edge', 'Edge', default: 2)]), [], []);

        $this->expectException(CheckEvaluationFailed::class);

        $this->action()->perform($random, $sheet, null, 0);
    }

    #[Test]
    public function aModifierThatCannotBeEvaluatedRollsNothing(): void
    {
        $sheet = SheetValues::of(new SheetTemplate([new NumberField('momentum', 'Momentum', default: 2)]), [], []);

        $this->expectException(CheckEvaluationFailed::class);

        $this->action()->perform(new ScriptedRandomNumberGenerator(), $sheet, null, 0);
    }

    #[Test]
    public function aBandBoundThatCannotBeEvaluatedRollsNothing(): void
    {
        $check = new Check('move', 'Move', null, DiceExpression::fromString('1d6'), null, null, [new CheckBand('miss', 'Miss', Formula::fromString('{ghost}')), new CheckBand('hit', 'Hit')], null, null, new CheckChallenge(2, 10));

        $this->expectException(CheckEvaluationFailed::class);

        $check->perform(new ScriptedRandomNumberGenerator(), $this->sheet(), null, 0);
    }

    #[Test]
    public function aScoreTooBigForAnIntegerOverflows(): void
    {
        // A constant dice total just below PHP_INT_MAX (as in CheckTest), so the modifier pushes the sum past it.
        // The two challenge dice are the only numbers the roll consumes, and it reaches the overflow guard after them.
        $huge = DiceExpression::fromString('9*1000000*1000000*1000000+223372*1000000*1000000+36854*1000000+773759');
        $check = new Check('huge', 'Huge', null, $huge, Formula::fromString('{edge} * 1000000 * 1000'), null, $this->bands(), null, null, new CheckChallenge(2, 10));

        $this->expectException(CheckEvaluationFailed::class);
        $this->expectExceptionMessageIsOrContains('the total of the check overflows.');

        $check->perform(new ScriptedRandomNumberGenerator(1, 1), $this->sheet(['edge' => 1]), null, 0);
    }

    #[Test]
    public function itAcceptsTheShapesOfTheContract(): void
    {
        self::assertSame(2, $this->action()->challenge?->count);
        self::assertNull($this->progress()->dice);
        $burnable = new Check('move', 'Move', null, DiceExpression::fromString('1d6'), null, null, $this->bands(), null, new CheckPush('Burn', []), new CheckChallenge(2, 10, 10, 'momentum'));
        self::assertSame('Burn', $burnable->push?->label);
    }

    /**
     * @return iterable<string, array{\Closure(): Check}>
     */
    public static function refusedShapes(): iterable
    {
        $bands = [new CheckBand('miss', 'Miss', 0), new CheckBand('hit', 'Hit')];
        $challenge = new CheckChallenge(2, 10);
        $dice = DiceExpression::fromString('1d6');
        $modifier = Formula::fromString('{edge}');

        yield 'dice, pool and challenge' => [static fn (): Check => new Check('c', 'C', null, $dice, null, null, $bands, new CheckPool(2, 6, 5), null, $challenge)];
        yield 'a pool and a challenge' => [static fn (): Check => new Check('c', 'C', null, null, null, null, $bands, new CheckPool(2, 6, 5), null, $challenge)];
        yield 'nothing rolled' => [static fn (): Check => new Check('c', 'C', null, null, $modifier, null, $bands)];
        yield 'a difficulty' => [static fn (): Check => new Check('c', 'C', null, $dice, null, new CheckDifficulty('Difficulty', 3), $bands, null, null, $challenge)];
        yield 'a progress roll without a modifier' => [static fn (): Check => new Check('c', 'C', null, null, null, null, $bands, null, null, $challenge)];
        yield 'a progress roll with a momentum' => [static fn (): Check => new Check('c', 'C', null, null, $modifier, null, $bands, null, null, new CheckChallenge(2, 10, null, 'momentum'))];
        yield 'a momentum with two dice' => [static fn (): Check => new Check('c', 'C', null, DiceExpression::fromString('2d6'), null, null, $bands, null, null, new CheckChallenge(2, 10, null, 'momentum'))];
        yield 'a momentum with arithmetic' => [static fn (): Check => new Check('c', 'C', null, DiceExpression::fromString('1d6+1'), null, null, $bands, null, null, new CheckChallenge(2, 10, null, 'momentum'))];
        yield 'a push without a momentum' => [static fn (): Check => new Check('c', 'C', null, $dice, null, null, $bands, null, new CheckPush('Burn', []), $challenge)];
        yield 'a push on a progress roll' => [static fn (): Check => new Check('c', 'C', null, null, $modifier, null, $bands, null, new CheckPush('Burn', []), $challenge)];
        yield 'a push with a maxDice' => [static fn (): Check => new Check('c', 'C', null, $dice, null, null, $bands, null, new CheckPush('Burn', [], 2), new CheckChallenge(2, 10, null, 'momentum'))];
        yield 'a push on a plain dice check' => [static fn (): Check => new Check('c', 'C', null, $dice, null, null, $bands, null, new CheckPush('Burn', []))];
    }

    /**
     * @param \Closure(): Check $build
     */
    #[Test]
    #[DataProvider('refusedShapes')]
    public function itRefusesShapesTheContractRefuses(\Closure $build): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $build();
    }

    /**
     * @return iterable<string, array{int, int, ?int, ?string}>
     */
    public static function badChallenges(): iterable
    {
        yield 'one die' => [1, 10, null, null];
        yield 'eleven dice' => [11, 10, null, null];
        yield 'one side' => [2, 1, null, null];
        yield '1001 sides' => [2, 1001, null, null];
        yield 'a cap of zero' => [2, 10, 0, null];
        yield 'a cap of 1001' => [2, 10, 1001, null];
        yield 'a momentum that is not a key' => [2, 10, null, 'Not A Key'];
        yield 'an empty momentum' => [2, 10, null, ''];
    }

    #[Test]
    #[DataProvider('badChallenges')]
    public function aChallengeValidatesItsBounds(int $count, int $sides, ?int $scoreMax, ?string $momentum): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CheckChallenge($count, $sides, $scoreMax, $momentum);
    }

    private function action(): Check
    {
        return new Check('move', 'Move', null, DiceExpression::fromString('1d6'), Formula::fromString('{edge}'), null, $this->bands(), null, null, new CheckChallenge(2, 10, 10, 'momentum'));
    }

    private function progress(?int $scoreMax = null): Check
    {
        return new Check('fulfill', 'Fulfill', null, null, Formula::fromString('{progress}'), null, $this->bands(), null, null, new CheckChallenge(2, 10, $scoreMax));
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
            new NumberField('progress', 'Progress', default: 7),
        ]), $values, []);
    }
}
