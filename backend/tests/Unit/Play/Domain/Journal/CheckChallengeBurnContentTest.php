<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Journal;

use App\Formula\Domain\Formula;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\Campaign\FlowRun\SheetChange;
use App\Play\Domain\GameSystem\Check\BurnResult;
use App\Play\Domain\GameSystem\Check\Check;
use App\Play\Domain\GameSystem\Check\CheckBand;
use App\Play\Domain\GameSystem\Check\CheckChallenge;
use App\Play\Domain\GameSystem\Check\CheckPush;
use App\Play\Domain\GameSystem\Check\ReferenceLabels;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Play\Domain\Journal\CheckAlreadyPushed;
use App\Play\Domain\Journal\CheckChallengeBurnContent;
use App\Play\Domain\Journal\CheckChallengeContent;
use App\Play\Domain\Journal\CheckContent;
use App\Play\Domain\Journal\InvalidJournalEntryContent;
use App\Randomness\Domain\DiceExpression;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The burn part of a challenge check entry (ADR 0049), on the roll of CheckChallengeContentTest's action roll: a 4 on
 * the die and Edge 2 make a score of 6 against the dice 3 and 9, a weak hit (one die beaten). Burning momentum 10 makes the
 * score 10 (capped): both dice beaten, a strong hit, and the cost set the momentum to its reset (2).
 */
#[CoversClass(CheckChallengeBurnContent::class)]
#[CoversClass(CheckChallengeContent::class)]
#[CoversClass(CheckContent::class)]
final class CheckChallengeBurnContentTest extends TestCase
{
    private static function template(): SheetTemplate
    {
        return new SheetTemplate([
            new NumberField('edge', 'Edge', default: 2),
            new NumberField('momentum', 'Momentum', min: -6, max: 10, default: 2),
        ]);
    }

    private static function check(): Check
    {
        $bands = [new CheckBand('miss', 'Miss', 0), new CheckBand('weak', 'Weak', 1), new CheckBand('strong', 'Strong')];

        return new Check('edge', 'Edge', null, DiceExpression::fromString('1d6'), Formula::fromString('{edge}'), null, $bands, null, new CheckPush('Burn momentum', []), new CheckChallenge(2, 10, 10, 'momentum'));
    }

    /**
     * @param list<int> $faces the action die, then the challenge dice
     */
    public static function edge(array $faces = [4, 3, 9]): CheckContent
    {
        $check = self::check();
        $result = $check->perform(new ScriptedRandomNumberGenerator(...$faces), SheetValues::of(self::template(), [], []), null, 0);

        return CheckContent::fromResult('c1', 'Ada', $check, $result, new ReferenceLabels(self::template()));
    }

    public static function burnResult(int $momentum = 10): BurnResult
    {
        $challenge = self::edge()->challenge() ?? self::fail('An action roll has a challenge part.');

        return self::check()->burn(SheetValues::of(self::template(), ['momentum' => $momentum], []), $challenge->roll(), $challenge->score);
    }

    public static function burnedEdge(): CheckContent
    {
        return self::edge()->burned('Burn momentum', self::burnResult(), [new SheetChange('momentum', 10, 2)]);
    }

    #[Test]
    public function burningKeepsTheFirstRollAndAddsTheBurnWithTheNewTotals(): void
    {
        $rolled = self::edge()->toArray();
        $burned = self::burnedEdge()->toArray();

        self::assertSame([4, 2, 'strong', 'Strong'], [$burned['diceTotal'], $burned['total'], $burned['bandKey'], $burned['bandLabel']]);
        self::assertSame($rolled['challenge']['dice'] ?? null, $burned['challenge']['dice'] ?? null, 'Each die keeps the flag of the first score.');
        self::assertSame([6, 2], [$burned['challenge']['score'] ?? null, $burned['challenge']['momentum'] ?? null]);
        self::assertSame([
            'label' => 'Burn momentum',
            'momentum' => 10,
            'score' => 10,
            'rolledBandKey' => 'weak',
            'rolledBandLabel' => 'Weak',
            'changes' => [['field' => 'momentum', 'before' => 10, 'after' => 2, 'dice' => null]],
        ], self::burnedEdge()->challenge()?->burn?->toArray());
        self::assertArrayNotHasKey('burn', $rolled['challenge'] ?? []);
    }

    #[Test]
    public function aMatchStaysDerivedFromTheFacesAfterTheBurn(): void
    {
        $match = self::edge([1, 5, 5]);
        $challenge = $match->challenge() ?? self::fail('An action roll has a challenge part.');
        $result = self::check()->burn(SheetValues::of(self::template(), ['momentum' => 8], []), $challenge->roll(), $challenge->score);

        $burned = $match->burned('Burn momentum', $result, []);

        self::assertSame(['match', 2, 'strong'], [$burned->special(), $burned->total(), $burned->bandKey()]);
    }

    #[Test]
    public function aBurnedEntryRoundTrips(): void
    {
        $burned = self::burnedEdge();

        self::assertEquals($burned, CheckContent::fromArray($burned->toArray()));
        self::assertSame($burned->toArray(), CheckContent::fromArray($burned->toArray())->toArray());
    }

    #[Test]
    public function aStoredChallengeWithoutABurnReadsAsNotBurned(): void
    {
        $stored = self::edge()->toArray();

        self::assertArrayNotHasKey('burn', $stored['challenge'] ?? []);
        self::assertNull(CheckContent::fromArray($stored)->challenge()?->burn);

        $stored['challenge']['burn'] = null;
        self::assertNull(CheckContent::fromArray($stored)->challenge()?->burn, 'A JSON null reads as no burn.');
    }

    #[Test]
    public function aRollIsBurnedOnce(): void
    {
        $this->expectException(CheckAlreadyPushed::class);

        self::burnedEdge()->burned('Burn momentum', self::burnResult(), []);
    }

    #[Test]
    public function onlyAChallengeRollCanBeBurned(): void
    {
        $check = new Check('skill', 'Skill', null, DiceExpression::fromString('1d10'), null, null, [new CheckBand('all', 'All')]);
        $result = $check->perform(new ScriptedRandomNumberGenerator(7), SheetValues::of(self::template(), [], []), null, 0);
        $dice = CheckContent::fromResult('c1', 'Ada', $check, $result, new ReferenceLabels(self::template()));

        $this->expectException(\LogicException::class);

        $dice->burned('Burn momentum', self::burnResult(), []);
    }

    #[Test]
    public function aLabelOfAnyLengthButOneToOneHundredIsRefused(): void
    {
        $this->expectException(InvalidJournalEntryContent::class);
        $this->expectExceptionMessageIsOrContains('"label"');

        self::edge()->burned(str_repeat('x', 101), self::burnResult(), []);
    }

    /**
     * @return iterable<string, array{callable(array<mixed>): array<mixed>, string}> a change to the stored burned entry and the refusal
     */
    public static function malformed(): iterable
    {
        yield 'a score that is not the momentum' => [static fn (array $b): array => ['score' => 9] + $b, '"burn"'];
        yield 'a momentum above the cap with the momentum as the score' => [static fn (array $b): array => ['momentum' => 12, 'score' => 12] + $b, '"burn"'];
        yield 'a burn that beats no more dice' => [static fn (array $b): array => ['momentum' => 8, 'score' => 8] + $b, '"burn"'];
        yield 'a negative momentum' => [static fn (array $b): array => ['momentum' => -3, 'score' => -3] + $b, '"burn"'];
        yield 'a blank label' => [static fn (array $b): array => ['label' => ' '] + $b, '"label"'];
        yield 'a label that is too long' => [static fn (array $b): array => ['label' => str_repeat('x', 101)] + $b, '"label"'];
        yield 'a blank rolled band' => [static fn (array $b): array => ['rolledBandKey' => ''] + $b, 'rolled band key'];
        yield 'a missing momentum' => [static fn (array $b): array => array_diff_key($b, ['momentum' => 1]), '"momentum"'];
        yield 'a momentum that is a string' => [static fn (array $b): array => ['momentum' => '10'] + $b, '"momentum"'];
        yield 'changes that are not a list' => [static fn (array $b): array => ['changes' => 'none'] + $b, '"changes"'];
        yield 'a change that is malformed' => [static fn (array $b): array => ['changes' => [['field' => 'momentum', 'before' => 1, 'after' => 2, 'dice' => 'x']]] + $b, '"dice"'];
    }

    /**
     * @param callable(array<mixed>): array<mixed> $change
     */
    #[Test]
    #[DataProvider('malformed')]
    public function aMalformedBurnIsRejected(callable $change, string $message): void
    {
        $stored = self::burnedEdge()->toArray();
        $burn = $stored['challenge']['burn'] ?? self::fail('The entry is burned.');
        $stored['challenge']['burn'] = $change($burn);

        $this->expectException(InvalidJournalEntryContent::class);
        $this->expectExceptionMessageIsOrContains($message);

        CheckContent::fromArray($stored);
    }

    #[Test]
    public function aBurnThatIsNotAnObjectIsRejected(): void
    {
        $stored = self::burnedEdge()->toArray();
        $stored['challenge']['burn'] = ['a', 'list'];

        $this->expectException(InvalidJournalEntryContent::class);

        CheckContent::fromArray($stored);
    }

    #[Test]
    public function aTotalThatIsNotTheDiceTheBurnBeatIsRejected(): void
    {
        $stored = self::burnedEdge()->toArray();
        $stored['total'] = 1;

        $this->expectException(InvalidJournalEntryContent::class);
        $this->expectExceptionMessageIsOrContains('burned score');

        CheckContent::fromArray($stored);
    }

    #[Test]
    public function aBurnOfARollWithoutAMomentumIsRejected(): void
    {
        $stored = self::burnedEdge()->toArray();
        $stored['challenge']['momentum'] = null;

        $this->expectException(InvalidJournalEntryContent::class);
        $this->expectExceptionMessageIsOrContains('"burn"');

        CheckContent::fromArray($stored);
    }

    #[Test]
    public function aBurnedEntryKeepsItsFirstScoreInvariants(): void
    {
        $stored = self::burnedEdge()->toArray();
        $stored['challenge']['score'] = 7;

        $this->expectException(InvalidJournalEntryContent::class);

        CheckContent::fromArray($stored);
    }
}
