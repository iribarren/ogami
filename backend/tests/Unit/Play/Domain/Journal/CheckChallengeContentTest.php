<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Journal;

use App\Formula\Domain\Formula;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\GameSystem\Check\Check;
use App\Play\Domain\GameSystem\Check\CheckBand;
use App\Play\Domain\GameSystem\Check\CheckChallenge;
use App\Play\Domain\GameSystem\Check\CheckPool;
use App\Play\Domain\GameSystem\Check\ReferenceLabels;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
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
 * The journal side of challenge dice (ADR 0049): an action roll (1d6 + edge 2 against 2d10, capped at 10,
 * with a momentum field) and a progress roll (a progress of 7 against 2d10), and the entries stored before.
 */
#[CoversClass(CheckChallengeContent::class)]
#[CoversClass(CheckContent::class)]
final class CheckChallengeContentTest extends TestCase
{
    private function template(): SheetTemplate
    {
        return new SheetTemplate([
            new NumberField('edge', 'Edge', default: 2),
            new NumberField('momentum', 'Momentum', min: -6, max: 10, default: 2),
            new NumberField('progress', 'Progress', default: 7),
        ]);
    }

    private function check(bool $progress): Check
    {
        $bands = [new CheckBand('miss', 'Miss', 0), new CheckBand('weak', 'Weak', 1), new CheckBand('strong', 'Strong')];

        return $progress
            ? new Check('fulfill', 'Fulfill', 'Mark it', null, Formula::fromString('{progress}'), null, $bands, null, null, new CheckChallenge(2, 10, 10))
            : new Check('edge', 'Edge', null, DiceExpression::fromString('1d6'), Formula::fromString('{edge}'), null, $bands, null, null, new CheckChallenge(2, 10, 10, 'momentum'));
    }

    /**
     * @param list<int>            $faces  the action die when it is rolled, then the challenge dice
     * @param array<string, mixed> $values
     */
    private function content(bool $progress, array $faces, array $values = []): CheckContent
    {
        $check = $this->check($progress);
        $result = $check->perform(new ScriptedRandomNumberGenerator(...$faces), SheetValues::of($this->template(), $values, []), null, 0);

        return CheckContent::fromResult('c1', 'Ada', $check, $result, new ReferenceLabels($this->template()));
    }

    #[Test]
    public function anActionRollKeepsTheScoreAndEachChallengeDie(): void
    {
        $content = $this->content(false, [4, 3, 9]);

        self::assertSame('1d6', $content->expression());
        self::assertSame([4, 2, 1, 'weak'], [$content->diceTotal(), $content->modifier(), $content->total(), $content->bandKey()]);
        self::assertSame(['sides' => 10, 'scoreMax' => 10, 'score' => 6, 'momentum' => 2, 'cancelled' => false, 'dice' => [['face' => 3, 'beaten' => true], ['face' => 9, 'beaten' => false]]], $content->toArray()['challenge'] ?? null);
        self::assertNull($content->special());
        self::assertNull($content->pool());
    }

    #[Test]
    public function aProgressRollHasNoActionDie(): void
    {
        $content = $this->content(true, [3, 8]);

        self::assertSame(['2d10', [], 0, 7, 1], [$content->expression(), $content->groups(), $content->diceTotal(), $content->modifier(), $content->total()]);
        $challenge = $content->challenge();
        self::assertInstanceOf(CheckChallengeContent::class, $challenge);
        self::assertSame([7, null], [$challenge->score, $challenge->momentum]);
    }

    #[Test]
    public function aCancelledActionDieIsStored(): void
    {
        $content = $this->content(false, [4, 5, 5], ['momentum' => -4]);

        $challenge = $content->challenge();
        self::assertInstanceOf(CheckChallengeContent::class, $challenge);
        self::assertSame([4, true, 2], [$content->diceTotal(), $challenge->cancelled, $challenge->score]);
    }

    #[Test]
    public function aMatchIsDerivedFromTheFacesNotStored(): void
    {
        $content = $this->content(false, [4, 5, 5]);

        self::assertSame('match', $content->special());
        self::assertArrayNotHasKey('special', $content->toArray()['challenge'] ?? []);
    }

    #[Test]
    public function aStoredSpecialKeyIsNeverRead(): void
    {
        $entry = $this->content(false, [4, 3, 9])->toArray();
        $entry['challenge']['special'] = 'match';

        self::assertNull(CheckContent::fromArray($entry)->special());
    }

    #[Test]
    public function aWholeEntryRoundTrips(): void
    {
        foreach ([$this->content(false, [4, 3, 9]), $this->content(false, [4, 5, 5], ['momentum' => -4]), $this->content(true, [3, 8])] as $content) {
            self::assertEquals($content, CheckContent::fromArray($content->toArray()));
            self::assertSame($content->toArray(), CheckContent::fromArray($content->toArray())->toArray());
        }
    }

    #[Test]
    public function anEntryStoredBeforeChallengeDiceReadsAsItDid(): void
    {
        $check = new Check('skill', 'Skill', null, DiceExpression::fromString('1d10'), null, null, [new CheckBand('all', 'All')]);
        $result = $check->perform(new ScriptedRandomNumberGenerator(7), SheetValues::of($this->template(), [], []), null, 0);
        $legacy = CheckContent::fromResult('c1', 'Ada', $check, $result, new ReferenceLabels($this->template()))->toArray();

        self::assertArrayNotHasKey('challenge', $legacy);
        $content = CheckContent::fromArray($legacy);
        self::assertNull($content->challenge());
        self::assertNull($content->special());
        self::assertSame($legacy, $content->toArray());
    }

    /**
     * @return iterable<string, array{callable(array<mixed>): array<mixed>, string}> a change to the stored action roll (4 on the die, faces 3 and 9) and the refusal
     */
    public static function malformed(): iterable
    {
        yield 'one side' => [static fn (array $e): array => self::withChallenge($e, ['sides' => 1]), '"sides"'];
        yield '1001 sides' => [static fn (array $e): array => self::withChallenge($e, ['sides' => 1001]), '"sides"'];
        yield 'one die' => [static fn (array $e): array => self::withChallenge($e, ['dice' => [['face' => 3, 'beaten' => true]]]), '"dice"'];
        yield 'eleven dice' => [static fn (array $e): array => self::withChallenge($e, ['dice' => array_fill(0, 11, ['face' => 3, 'beaten' => true])]), '"dice"'];
        yield 'a face above the sides' => [static fn (array $e): array => self::withChallenge($e, ['dice' => [['face' => 11, 'beaten' => false], ['face' => 9, 'beaten' => false]]]), '"face"'];
        yield 'a beaten die that is not below the score' => [static fn (array $e): array => self::withChallenge($e, ['dice' => [['face' => 3, 'beaten' => true], ['face' => 9, 'beaten' => true]]]), '"beaten"'];
        yield 'a tie marked beaten' => [static fn (array $e): array => self::withChallenge($e, ['dice' => [['face' => 3, 'beaten' => true], ['face' => 6, 'beaten' => true]]]), '"beaten"'];
        yield 'a cap of zero' => [static fn (array $e): array => self::withChallenge($e, ['scoreMax' => 0]), '"scoreMax"'];
        yield 'a cap of 1001' => [static fn (array $e): array => self::withChallenge($e, ['scoreMax' => 1001]), '"scoreMax"'];
        yield 'a score above the cap' => [static fn (array $e): array => self::withChallenge($e, ['scoreMax' => 5]), '"score"'];
        yield 'a cancelled die with a momentum that is not negative' => [static fn (array $e): array => self::withChallenge($e, ['cancelled' => true]), '"cancelled"'];
        yield 'a cancelled die without a momentum' => [static fn (array $e): array => self::withChallenge($e, ['cancelled' => true, 'momentum' => null]), '"cancelled"'];
        yield 'a total that is not the dice beaten' => [static fn (array $e): array => ['total' => 2] + $e, '"total"'];
        yield 'a pool beside the challenge' => [static fn (array $e): array => ['pool' => self::poolPart()] + $e, '"pool"'];
        yield 'a difficulty' => [static fn (array $e): array => ['difficulty' => 3] + $e, '"difficulty"'];
        yield 'a score that ignores the die' => [static fn (array $e): array => self::withChallenge($e, ['score' => 7, 'dice' => [['face' => 3, 'beaten' => true], ['face' => 9, 'beaten' => false]]]), '"score"'];
        yield 'a negative momentum equal to the die that did not cancel it' => [static fn (array $e): array => self::withChallenge($e, ['momentum' => -4]), '"cancelled"'];
    }

    /**
     * @param callable(array<mixed>): array<mixed> $change
     */
    #[Test]
    #[DataProvider('malformed')]
    public function aMalformedChallengeEntryIsRejected(callable $change, string $message): void
    {
        $this->expectException(InvalidJournalEntryContent::class);
        $this->expectExceptionMessageIsOrContains($message);

        CheckContent::fromArray($change($this->content(false, [4, 3, 9])->toArray()));
    }

    /**
     * @return iterable<string, array{callable(array<mixed>): array<mixed>, string}> a change to the stored progress roll (progress 7, faces 3 and 8) and the refusal
     */
    public static function malformedProgress(): iterable
    {
        yield 'a dice total' => [static fn (array $e): array => ['diceTotal' => 3] + $e, '"diceTotal"'];
        yield 'a momentum' => [static fn (array $e): array => self::withChallenge($e, ['momentum' => -2]), '"momentum"'];
        yield 'a score that is not the modifier' => [static fn (array $e): array => self::withChallenge($e, ['score' => 8]), '"score"'];
        yield 'a score above the cap' => [static fn (array $e): array => self::withChallenge($e, ['scoreMax' => 5]), '"score"'];
    }

    /**
     * @param callable(array<mixed>): array<mixed> $change
     */
    #[Test]
    #[DataProvider('malformedProgress')]
    public function aMalformedProgressEntryIsRejected(callable $change, string $message): void
    {
        $this->expectException(InvalidJournalEntryContent::class);
        $this->expectExceptionMessageIsOrContains($message);

        CheckContent::fromArray($change($this->content(true, [3, 8])->toArray()));
    }

    /**
     * @return array<mixed> the pool part of an entry of a pool check
     */
    private static function poolPart(): array
    {
        $check = new Check('pool', 'Pool', null, null, null, null, [new CheckBand('all', 'All')], new CheckPool(2, 6, 5));
        $result = $check->perform(new ScriptedRandomNumberGenerator(5, 6), SheetValues::of(new SheetTemplate([]), [], []), null, 0);
        $pool = CheckContent::fromResult('c1', 'Ada', $check, $result, new ReferenceLabels(new SheetTemplate([])))->toArray()['pool'] ?? null;
        self::assertIsArray($pool);

        return $pool;
    }

    /**
     * @param array<mixed> $entry
     * @param array<mixed> $changes
     *
     * @return array<mixed>
     */
    private static function withChallenge(array $entry, array $changes): array
    {
        $challenge = $entry['challenge'] ?? null;
        self::assertIsArray($challenge);

        return ['challenge' => $changes + $challenge] + $entry;
    }
}
