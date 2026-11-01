<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\Check;

use App\Formula\Domain\Formula;
use App\Randomness\Domain\ChallengeDice;
use App\Randomness\Domain\DiceExpression;
use App\Studio\Domain\Check\CheckDifficultyRange;
use App\Studio\Domain\Check\CheckTestBurn;
use App\Studio\Domain\Check\CheckTestChallenge;
use App\Studio\Domain\Check\CheckTestChallengeRoll;
use App\Studio\Domain\Check\CheckTestPool;
use App\Studio\Domain\Check\CheckTestPush;
use App\Studio\Domain\Check\CheckTestRoll;
use App\Studio\Domain\Check\CheckTestRollFailed;
use App\Studio\Domain\Check\CheckTestRollNotPushable;
use App\Studio\Domain\Check\CheckTestSpecialResult;
use App\Studio\Domain\Check\InvalidTestRoll;
use App\Studio\Domain\Check\OutcomeBand;
use App\Studio\Domain\Check\SampleSheet;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test-rolling a challenge check in the order of Play's Check::perform (ADR 0049, the cases of Play's
 * CheckChallengeTest): an action die of 1d6, plus the Edge modifier (2), against two challenge dice of
 * 10 sides, the score capped at 10; bands miss up to 0, weak up to 1 and strong above. Burning is
 * stateless: the faces of the roll come back with the request, and the momentum is the sample sheet's.
 */
#[CoversClass(CheckTestRoll::class)]
#[CoversClass(CheckTestChallenge::class)]
#[CoversClass(CheckTestChallengeRoll::class)]
#[CoversClass(CheckTestBurn::class)]
#[CoversClass(CheckTestRollNotPushable::class)]
final class CheckTestRollChallengeTest extends TestCase
{
    #[Test]
    public function anActionRollScoresTheDieAndTheModifierAgainstEachChallengeDie(): void
    {
        // Score 4 + 2 = 6: it beats the 5, not the 7.
        $result = $this->edge()->perform(new ScriptedRandomNumberGenerator(4, 5, 7), $this->sheet(), null, 0);

        $challenge = $result->challenge ?? self::fail('A challenge check rolls challenge dice.');
        self::assertSame(4, $result->roll?->total());
        self::assertSame([4, 6, 10, 2, 'momentum', false], [$challenge->actionDie, $challenge->score, $challenge->scoreMax, $challenge->momentum, $challenge->momentumField, $challenge->cancelled]);
        self::assertSame([5, 7], $challenge->roll->faces());
        self::assertSame([2, 0, 1, 'weak'], [$result->modifier, $result->situational, $result->total, $result->band->key]);
        self::assertNull($result->difficulty);
        self::assertNull($result->special);
        self::assertNull($challenge->burn);
        self::assertSame('Burn momentum', $result->push?->label);
    }

    #[Test]
    public function aTieBetweenAChallengeDieAndTheScoreGoesToTheDice(): void
    {
        $result = $this->edge()->perform(new ScriptedRandomNumberGenerator(4, 6, 3), $this->sheet(), null, 0);

        self::assertSame([1, 'weak'], [$result->total, $result->band->key]);
    }

    #[Test]
    public function theScoreIsCappedAtTheScoreMaxEvenWithTheSituationalModifier(): void
    {
        // 6 + 2 + 5 = 13, capped at 10: the 10 ties and is not beaten.
        $result = $this->edge()->perform(new ScriptedRandomNumberGenerator(6, 9, 10), $this->sheet(), null, 5);

        self::assertSame([10, 1, 5], [$result->challenge?->score, $result->total, $result->situational]);
    }

    #[Test]
    public function aSituationalModifierNearTheCapStillCountsBelowIt(): void
    {
        // 6 + 2 + 1 = 9, below the cap of 10: the 9 ties and is not beaten, the 8 is.
        $result = $this->edge()->perform(new ScriptedRandomNumberGenerator(6, 9, 8), $this->sheet(), null, 1);

        self::assertSame([9, 1], [$result->challenge?->score, $result->total]);
    }

    #[Test]
    public function aProgressRollScoresTheModifierAloneAndCapsIt(): void
    {
        // No dice are rolled: the generator holds the two challenge dice only.
        $result = $this->progress('{hands} * 2')->perform(new ScriptedRandomNumberGenerator(7, 8), $this->sheet(), null, 1);

        $challenge = $result->challenge ?? self::fail('A progress roll rolls challenge dice.');
        self::assertNull($result->roll);
        self::assertSame([null, 9, null, false], [$challenge->actionDie, $challenge->score, $challenge->momentum, $challenge->cancelled]);
        self::assertSame([2, 'strong'], [$result->total, $result->band->key]);

        $capped = $this->progress('{hands} * 4')->perform(new ScriptedRandomNumberGenerator(9, 10), $this->sheet(), null, 0);
        self::assertSame([10, 1], [$capped->challenge?->score, $capped->total]);
    }

    #[Test]
    public function negativeMomentumEqualToTheActionDieCancelsItOnlyAtEquality(): void
    {
        $sheet = $this->sheet(momentum: -4);

        $cancelled = $this->edge()->perform(new ScriptedRandomNumberGenerator(4, 1, 3), $sheet, null, 0);
        self::assertSame([true, 2, 4, -4, 1], [$cancelled->challenge?->cancelled, $cancelled->challenge?->score, $cancelled->challenge?->actionDie, $cancelled->challenge?->momentum, $cancelled->total]);

        $other = $this->edge()->perform(new ScriptedRandomNumberGenerator(5, 1, 3), $sheet, null, 0);
        self::assertSame([false, 7], [$other->challenge?->cancelled, $other->challenge?->score]);

        $positive = $this->edge()->perform(new ScriptedRandomNumberGenerator(4, 1, 3), $this->sheet(momentum: 4), null, 0);
        self::assertSame([false, 6], [$positive->challenge?->cancelled, $positive->challenge?->score]);
    }

    #[Test]
    public function everyChallengeDieShowingTheSameFaceIsAMatchWhateverTheBand(): void
    {
        $result = $this->edge()->perform(new ScriptedRandomNumberGenerator(4, 3, 3), $this->sheet(), null, 0);
        self::assertSame([CheckTestSpecialResult::Match, 2, 'strong', null], [$result->special, $result->total, $result->band->key, $result->rolledSpecial]);

        $apart = $this->edge()->perform(new ScriptedRandomNumberGenerator(4, 3, 4), $this->sheet(), null, 0);
        self::assertNull($apart->special);
    }

    #[Test]
    public function aMultiDieActionRollWithNoMomentumIsValidAndItsDieIsTheDiceTotal(): void
    {
        $check = new CheckTestRoll('edge', DiceExpression::fromString('2d6'), null, null, $this->bands(), null, null, new CheckTestChallenge(new ChallengeDice(2, 10)));

        $result = $check->perform(new ScriptedRandomNumberGenerator(3, 4, 6, 8), $this->sheet(), null, 0);

        self::assertSame([7, 7, null, null, 1], [$result->challenge?->actionDie, $result->challenge?->score, $result->challenge?->momentum, $result->challenge?->momentumField, $result->total]);
    }

    #[Test]
    public function aCheckThatCannotBeEvaluatedRollsNothing(): void
    {
        $this->assertRollsNothing($this->edge('{hands} / {zero}'));
        $this->assertRollsNothing($this->edge(momentum: 'ghost'));
        $this->assertRollsNothing($this->edge(bands: [new OutcomeBand('low', 'Low', Formula::fromString('{hands} / {zero}')), new OutcomeBand('high', 'High')]));
        $this->assertRollsNothing($this->progress('{hands} / {zero}'));
    }

    #[Test]
    public function aScoreThatOverflowsFailsInsteadOfBecomingAFloat(): void
    {
        $check = new CheckTestRoll('huge', DiceExpression::fromString('9*1000000*1000000*1000000+223372*1000000*1000000+36854*1000000+773759'), Formula::fromString('{hands} * 1000000 * 1000'), null, $this->bands(), null, null, new CheckTestChallenge(new ChallengeDice(2, 10)));

        try {
            // The dice total is only known once they are rolled, so the challenge dice are rolled too before the guard.
            $check->perform(new ScriptedRandomNumberGenerator(3, 4), $this->sheet(hands: 1), null, 0);
            self::fail('The score overflows.');
        } catch (CheckTestRollFailed $failed) {
            self::assertSame('Check "huge" cannot be test-rolled: the total of the check overflows.', $failed->getMessage());
        }
    }

    #[Test]
    public function aDifficultyIsRefusedForACheckThatDeclaresNoneAndNothingIsRolled(): void
    {
        try {
            $this->edge()->perform(new ScriptedRandomNumberGenerator(), $this->sheet(), 3, 0);
            self::fail('The difficulty was accepted.');
        } catch (InvalidTestRoll $invalid) {
            self::assertStringStartsWith('difficulty: ', $invalid->getMessage());
        }
    }

    #[Test]
    public function aSituationalModifierOutsideTheRangeIsRefusedAndNothingIsRolled(): void
    {
        $this->expectException(InvalidTestRoll::class);

        $this->edge()->perform(new ScriptedRandomNumberGenerator(), $this->sheet(), null, 101);
    }

    #[Test]
    public function aBurnRaisesTheScoreToTheMomentumAndRollsNoDice(): void
    {
        // Score 2 + 2 = 4 beat the 3 only. Momentum 9 beats both. An empty generator fails if it is asked for a number.
        $result = $this->edge()->push(new ScriptedRandomNumberGenerator(), $this->sheet(momentum: 9), null, 0, [2, 5, 3]);

        $challenge = $result->challenge ?? self::fail('A burned roll keeps its challenge dice.');
        self::assertNull($result->roll);
        self::assertSame([2, 9, 9, 2, 'strong', 1], [$result->total, $challenge->score, $challenge->momentum, $result->modifier, $result->band->key, $result->rolledSuccesses]);
        self::assertSame([2, 9, 9], [$challenge->actionDie, $challenge->burn?->score, $challenge->burn?->momentum]);
        self::assertSame([5, 3], $challenge->roll->faces());
        self::assertFalse($challenge->cancelled);
        self::assertSame('Burn momentum', $result->push?->label);
    }

    #[Test]
    public function aBurnIsCappedAtTheScoreMaxAndMustStillBeatMoreDice(): void
    {
        $capped = $this->edge(scoreMax: 7);

        // Momentum 10 burns to 7: it beats the 6 the score of 4 did not.
        $result = $capped->push(new ScriptedRandomNumberGenerator(), $this->sheet(momentum: 10), null, 0, [2, 6, 9]);
        self::assertSame([7, 7, 10, 1], [$result->challenge?->score, $result->challenge?->burn?->score, $result->challenge?->burn?->momentum, $result->total]);

        // The cap keeps it from reaching the 8.
        $this->assertNotBurnable($capped, $this->sheet(momentum: 10), [2, 8, 9]);
    }

    #[Test]
    public function aBurnThatBeatsAsManyDiceAsTheScoreDidIsRefused(): void
    {
        // Score 4 beats the 3; momentum 6 beats the 3 again and not the 8.
        $this->assertNotBurnable($this->edge(), $this->sheet(momentum: 6), [2, 3, 8]);
        // A momentum equal to the score beats nothing more.
        $this->assertNotBurnable($this->edge(), $this->sheet(momentum: 4), [2, 3, 8]);
        // A momentum equal to a die ties it, and a tie goes to the dice.
        $this->assertNotBurnable($this->edge(), $this->sheet(momentum: 8), [2, 8, 8]);
    }

    #[Test]
    public function aMomentumOfZeroOrLessBeatsNothingSoItCannotBeBurned(): void
    {
        $this->assertNotBurnable($this->edge(), $this->sheet(momentum: 0), [2, 1, 1]);
        $this->assertNotBurnable($this->edge(), $this->sheet(momentum: -3), [2, 1, 1]);
    }

    #[Test]
    public function aBurnRebuildsTheFirstScoreFromTheDieTheModifierAndTheSituationalModifier(): void
    {
        // Die 2 + Edge 2 + situational 3 = 7 beats the 6; momentum 8 beats the 7 too.
        $result = $this->edge()->push(new ScriptedRandomNumberGenerator(), $this->sheet(momentum: 8), null, 3, [2, 6, 7]);
        self::assertSame([2, 1, 8, 3], [$result->total, $result->rolledSuccesses, $result->challenge?->score, $result->situational]);

        // Without that situational modifier the score would be 4 and momentum 7 would beat the 6: the situational modifier is what refuses it.
        $this->assertNotBurnable($this->edge(), $this->sheet(momentum: 7), [2, 6, 7], 3);
    }

    #[Test]
    public function aBurnKeepsTheMatchAndTheBandsAreReadAgainOnTheBurnedTotal(): void
    {
        $result = $this->edge()->push(new ScriptedRandomNumberGenerator(), $this->sheet(momentum: 9), null, 0, [2, 5, 5]);

        self::assertSame([CheckTestSpecialResult::Match, CheckTestSpecialResult::Match, 0, 2, 'strong'], [$result->special, $result->rolledSpecial, $result->rolledSuccesses, $result->total, $result->band->key]);
        self::assertSame([0, 1, null], $result->bounds);
    }

    /**
     * @return iterable<string, array{list<int>}>
     */
    public static function invalidFaces(): iterable
    {
        yield 'no faces' => [[]];
        yield 'the action die only' => [[2]];
        yield 'a challenge die short' => [[2, 5]];
        yield 'a challenge die too many' => [[2, 5, 3, 4]];
        yield 'an action die of zero' => [[0, 5, 3]];
        yield 'an action die above the sides' => [[7, 5, 3]];
        yield 'a challenge face of zero' => [[2, 0, 3]];
        yield 'a challenge face above the sides' => [[2, 5, 11]];
    }

    /**
     * @param list<int> $faces
     */
    #[Test]
    #[DataProvider('invalidFaces')]
    public function facesThatAreNotTheRollOfTheCheckAreRefusedAndNothingIsRolled(array $faces): void
    {
        try {
            $this->edge()->push(new ScriptedRandomNumberGenerator(), $this->sheet(momentum: 9), null, 0, $faces);
            self::fail('The faces were accepted.');
        } catch (InvalidTestRoll $invalid) {
            self::assertStringStartsWith('faces: ', $invalid->getMessage());
        }
    }

    #[Test]
    public function aBurnWithADifficultyOrAnOutOfRangeSituationalModifierIsRefused(): void
    {
        $this->expectException(InvalidTestRoll::class);

        $this->edge()->push(new ScriptedRandomNumberGenerator(), $this->sheet(momentum: 9), 3, 0, [2, 5, 3]);
    }

    #[Test]
    public function aBurnThatCannotBeEvaluatedBurnsNothing(): void
    {
        $this->expectException(CheckTestRollFailed::class);

        $this->edge(momentum: 'ghost')->push(new ScriptedRandomNumberGenerator(), $this->sheet(), null, 0, [2, 5, 3]);
    }

    #[Test]
    public function aCheckWithNoBurnAndAProgressRollCannotBeBurned(): void
    {
        $noBurn = new CheckTestRoll('edge', DiceExpression::fromString('1d6'), Formula::fromString('{edge}'), null, $this->bands(), null, null, new CheckTestChallenge(new ChallengeDice(2, 10), 10, 'momentum'));

        foreach ([[$noBurn, [2, 5, 3]], [$this->progress('{hands}'), [5, 3]]] as [$check, $faces]) {
            try {
                $check->push(new ScriptedRandomNumberGenerator(), $this->sheet(momentum: 9), null, 0, $faces);
                self::fail('The roll was burned.');
            } catch (CheckTestRollNotPushable $refused) {
                self::assertStringContainsString('declares no push', $refused->getMessage());
            }
        }
    }

    #[Test]
    public function aDiceCheckHasNoChallengeInItsResult(): void
    {
        $check = new CheckTestRoll('plain', DiceExpression::fromString('1d6'), null, null, $this->bands());

        self::assertNull($check->perform(new ScriptedRandomNumberGenerator(3), $this->sheet(), null, 0)->challenge);
    }

    #[Test]
    public function aStoredChallengeCheckIsReadWithItsDiceCapMomentumAndBurn(): void
    {
        $check = CheckTestRoll::fromStored($this->stored());

        $challenge = $check->challenge ?? self::fail('A challenge check has a challenge.');
        self::assertSame([2, 10, 10, 'momentum', 'Burn momentum'], [$challenge->dice->count, $challenge->dice->sides, $challenge->scoreMax, $challenge->momentum, $check->push?->label]);
        self::assertNull($check->pool);

        $progress = CheckTestRoll::fromStored($this->storedProgress());
        self::assertNull($progress->dice);
        self::assertInstanceOf(CheckTestChallenge::class, $progress->challenge);

        $plain = CheckTestRoll::fromStored($this->storedDice(['challenge' => null]));
        self::assertNull($plain->challenge);

        $withoutDice = CheckTestRoll::fromStored([...$this->stored(), 'challenge' => ['count' => 3, 'sides' => 6], 'push' => null]);
        self::assertNull($withoutDice->challenge?->momentum);
    }

    #[Test]
    public function aNullChallengeBesideAPoolReadsAsNotSet(): void
    {
        $check = CheckTestRoll::fromStored([
            'key' => 'grit', 'label' => 'Grit', 'challenge' => null,
            'pool' => ['count' => 3, 'sides' => 6, 'successAt' => 5],
            'bands' => [['key' => 'only', 'label' => 'Only']],
        ]);

        self::assertNull($check->challenge);
        self::assertNotNull($check->pool);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unreadable(): iterable
    {
        $challenge = ['count' => 2, 'sides' => 10, 'scoreMax' => 10, 'momentum' => 'momentum'];

        yield 'a challenge that is not an object' => [['challenge' => 'no']];
        yield 'a challenge that is a list' => [['challenge' => [2, 10]]];
        yield 'a count that is not an integer' => [['challenge' => [...$challenge, 'count' => '2']]];
        yield 'a count below the kernel bounds' => [['challenge' => [...$challenge, 'count' => 1]]];
        yield 'a count above the kernel bounds' => [['challenge' => [...$challenge, 'count' => 11]]];
        yield 'no count' => [['challenge' => ['sides' => 10]]];
        yield 'sides that are not an integer' => [['challenge' => [...$challenge, 'sides' => 10.5]]];
        yield 'sides below the kernel bounds' => [['challenge' => [...$challenge, 'sides' => 1]]];
        yield 'a score cap that is not an integer' => [['challenge' => [...$challenge, 'scoreMax' => '10']]];
        yield 'a score cap of zero' => [['challenge' => [...$challenge, 'scoreMax' => 0]]];
        yield 'a score cap above the bounds' => [['challenge' => [...$challenge, 'scoreMax' => 1001]]];
        yield 'a momentum that is not a string' => [['challenge' => [...$challenge, 'momentum' => 4]]];
        yield 'a momentum that is not a field key' => [['challenge' => [...$challenge, 'momentum' => 'Not A Key']]];
        yield 'a pool beside the challenge' => [['pool' => ['count' => 2, 'sides' => 6, 'successAt' => 5]]];
        yield 'a difficulty' => [['difficulty' => ['default' => 3]]];
        yield 'a burn with no momentum' => [['challenge' => ['count' => 2, 'sides' => 10]]];
        yield 'a momentum with dice that roll several dice' => [['dice' => '2d6']];
        yield 'a momentum with no dice' => [['dice' => null]];
        yield 'a push that is not a push' => [['push' => 'burn']];
    }

    /**
     * @param array<string, mixed> $changes
     */
    #[Test]
    #[DataProvider('unreadable')]
    public function aStoredChallengeCheckTheContractWouldNotHaveLetThroughIsUnreadable(array $changes): void
    {
        $this->expectException(CheckTestRollFailed::class);
        $this->expectExceptionMessageMatches('/^The saved draft cannot be test-rolled: /');

        CheckTestRoll::fromStored([...$this->stored(), ...$changes]);
    }

    #[Test]
    public function aCheckRollsExactlyOneOfDiceAndAPoolOrAChallengeWithOrWithoutDice(): void
    {
        $challenge = new CheckTestChallenge(new ChallengeDice(2, 10));

        // A pool and a challenge together break the invariant, with dice or without.
        $pool = new CheckTestPool(2, 6, 5);
        $this->assertInvalid(fn (): CheckTestRoll => new CheckTestRoll('x', null, null, null, $this->bands(), $pool, null, $challenge));
        $this->assertInvalid(fn (): CheckTestRoll => new CheckTestRoll('x', DiceExpression::fromString('1d6'), null, null, $this->bands(), $pool, null, $challenge));
        // Neither dice, pool nor challenge.
        $this->assertInvalid(fn (): CheckTestRoll => new CheckTestRoll('x', null, null, null, $this->bands()));
        // A push needs a pool or a challenge with a momentum.
        $this->assertInvalid(fn (): CheckTestRoll => new CheckTestRoll('x', DiceExpression::fromString('1d6'), null, null, $this->bands(), null, new CheckTestPush('Burn'), $challenge));
        $this->assertInvalid(fn (): CheckTestRoll => new CheckTestRoll('x', DiceExpression::fromString('1d6'), null, null, $this->bands(), null, new CheckTestPush('Burn')));
        // A difficulty is refused beside challenge dice, which are the difficulty.
        $this->assertInvalid(fn (): CheckTestRoll => new CheckTestRoll('x', DiceExpression::fromString('1d6'), null, new CheckDifficultyRange(3), $this->bands(), null, null, $challenge));
        // A burn spends a momentum, which is read beside exactly one action die.
        $this->assertInvalid(fn (): CheckTestRoll => new CheckTestRoll('x', DiceExpression::fromString('1d6+1'), null, null, $this->bands(), null, new CheckTestPush('Burn'), new CheckTestChallenge(new ChallengeDice(2, 10), null, 'momentum')));
        $this->assertInvalid(static fn (): CheckTestChallenge => new CheckTestChallenge(new ChallengeDice(2, 10), 0));
    }

    private function assertInvalid(callable $build): void
    {
        try {
            $build();
            self::fail('The invariant was not enforced.');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    private function assertRollsNothing(CheckTestRoll $check): void
    {
        try {
            // A generator with no number left throws a LogicException if a die is rolled.
            $check->perform(new ScriptedRandomNumberGenerator(), $this->sheet(), null, 0);
            self::fail('The check cannot be evaluated.');
        } catch (CheckTestRollFailed) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @param list<int> $faces
     */
    private function assertNotBurnable(CheckTestRoll $check, SampleSheet $sheet, array $faces, int $situational = 0): void
    {
        try {
            $check->push(new ScriptedRandomNumberGenerator(), $sheet, null, $situational, $faces);
            self::fail('The burn was accepted.');
        } catch (CheckTestRollNotPushable $refused) {
            self::assertStringContainsString('cannot burn momentum', $refused->getMessage());
        }
    }

    /**
     * @return non-empty-list<OutcomeBand>
     */
    private function bands(): array
    {
        return [new OutcomeBand('miss', 'Miss', 0), new OutcomeBand('weak', 'Weak', 1), new OutcomeBand('strong', 'Strong')];
    }

    /**
     * @param ?non-empty-list<OutcomeBand> $bands
     */
    private function edge(string $modifier = '{edge}', ?int $scoreMax = 10, string $momentum = 'momentum', ?array $bands = null): CheckTestRoll
    {
        return new CheckTestRoll('edge', DiceExpression::fromString('1d6'), Formula::fromString($modifier), null, $bands ?? $this->bands(), null, new CheckTestPush('Burn momentum'), new CheckTestChallenge(new ChallengeDice(2, 10), $scoreMax, $momentum));
    }

    private function progress(string $modifier): CheckTestRoll
    {
        return new CheckTestRoll('fulfill', null, Formula::fromString($modifier), null, $this->bands(), null, null, new CheckTestChallenge(new ChallengeDice(2, 10), 10));
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        return [
            'key' => 'edge',
            'label' => 'Edge',
            'dice' => '1d6',
            'modifier' => '{edge}',
            'challenge' => ['count' => 2, 'sides' => 10, 'scoreMax' => 10, 'momentum' => 'momentum'],
            'push' => ['label' => 'Burn momentum', 'effects' => []],
            'bands' => [['key' => 'miss', 'label' => 'Miss', 'upTo' => 0], ['key' => 'strong', 'label' => 'Strong']],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function storedProgress(): array
    {
        return ['key' => 'fulfill', 'label' => 'Fulfill', 'modifier' => '{hands}', 'challenge' => ['count' => 2, 'sides' => 10], 'bands' => [['key' => 'only', 'label' => 'Only']]];
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function storedDice(array $changes): array
    {
        return [...['key' => 'plain', 'label' => 'Plain', 'dice' => '1d6', 'bands' => [['key' => 'only', 'label' => 'Only']]], ...$changes];
    }

    private function sheet(int $hands = 4, int $momentum = 2): SampleSheet
    {
        return SampleSheet::fromDocument([
            'sheet' => ['fields' => [
                ['key' => 'hands', 'label' => 'Hands', 'type' => 'number', 'default' => 4],
                ['key' => 'zero', 'label' => 'Zero', 'type' => 'number'],
                ['key' => 'momentum', 'label' => 'Momentum', 'type' => 'number', 'min' => -6, 'max' => 10, 'default' => 2],
                ['key' => 'edge', 'label' => 'Edge', 'type' => 'derived', 'formula' => '2'],
            ]],
            'trackers' => [],
        ], ['hands' => $hands, 'momentum' => $momentum], []);
    }
}
