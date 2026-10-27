<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Journal;

use App\Formula\Domain\Formula;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\GameSystem\Check\Check;
use App\Play\Domain\GameSystem\Check\CheckBand;
use App\Play\Domain\GameSystem\Check\CheckDifficulty;
use App\Play\Domain\GameSystem\Check\CheckPool;
use App\Play\Domain\GameSystem\Check\CheckPush;
use App\Play\Domain\GameSystem\Check\ReferenceLabels;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Play\Domain\Journal\CheckContent;
use App\Play\Domain\Journal\CheckPoolContent;
use App\Play\Domain\Journal\CheckPushContent;
use App\Play\Domain\Journal\InvalidJournalEntryContent;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The journal side of Hunger dice, critical pairs and special results (ADR 0044): a 5d10 pool with 2
 * Hunger dice and a critical bonus of 2 (difficulty 3, push capped at 2 dice), and the legacy entries
 * stored before: pool dice only, no bonus, no special.
 */
#[CoversClass(CheckPoolContent::class)]
#[CoversClass(CheckPushContent::class)]
#[CoversClass(CheckContent::class)]
final class CheckHungerContentTest extends TestCase
{
    private function template(): SheetTemplate
    {
        return new SheetTemplate([new NumberField('hunger', 'Hunger')]);
    }

    private function check(): Check
    {
        return new Check(
            'persuade',
            'Persuade',
            null,
            null,
            null,
            new CheckDifficulty('Difficulty', 3, 1, 10),
            [new CheckBand('fail', 'Fail', Formula::fromString('{check:difficulty} - 1')), new CheckBand('win', 'Win')],
            new CheckPool(5, 10, 6, 2, Formula::fromString('{hunger}')),
            new CheckPush('Spend Willpower', [], 2),
        );
    }

    /**
     * @param list<int> $faces
     */
    private function content(array $faces): CheckContent
    {
        $check = $this->check();
        $result = $check->perform(new ScriptedRandomNumberGenerator(...$faces), SheetValues::of($this->template(), ['hunger' => 2], []), 3, 0);

        return CheckContent::fromResult('char-1', 'Vi', $check, $result, new ReferenceLabels($this->template()));
    }

    /**
     * @param list<int> $faces
     */
    private function pushedContent(CheckContent $content, array $faces): CheckContent
    {
        $check = $this->check();
        $pool = $content->pool() ?? throw new \LogicException('A pool check.');
        $result = $check->push(new ScriptedRandomNumberGenerator(...$faces), SheetValues::of($this->template(), ['hunger' => 2], []), $pool->roll(), 3);

        return $content->pushed('Spend Willpower', $result, []);
    }

    #[Test]
    public function aHungerRollKeepsTheGroupOfEachDieTheBonusAndTheSpecialResult(): void
    {
        $content = $this->content([10, 2, 2, 10, 3]);

        self::assertSame('messyCritical', $content->special());
        self::assertSame(4, $content->total());
        self::assertSame(4, $content->diceTotal());
        self::assertSame('win', $content->bandKey());

        $pool = self::poolOf($content->toArray());
        self::assertSame(['pool', 'pool', 'pool', 'hunger', 'hunger'], array_column(self::diceOf($pool), 'group'));
        self::assertSame(2, $pool['criticalsBonus']);
        self::assertSame('messyCritical', $pool['special']);
        self::assertSame(2, $pool['successes'], 'The successes stay the dice that succeeded.');
        self::assertEquals($content, CheckContent::fromArray($content->toArray()));
    }

    #[Test]
    public function aRollWithoutASpecialResultStoresNone(): void
    {
        $content = $this->content([7, 3, 3, 3, 3]);

        self::assertNull($content->special());
        self::assertArrayNotHasKey('special', self::poolOf($content->toArray()));
    }

    #[Test]
    public function aPushKeepsTheHungerDiceAndTheSpecialResultOfThePushedRoll(): void
    {
        $content = $this->pushedContent($this->content([2, 3, 2, 1, 3]), [10, 10]);

        self::assertSame('bestialFailure', $this->content([2, 3, 2, 1, 3])->special());
        self::assertSame('critical', $content->special(), 'The special result is the pushed roll’s.');
        self::assertSame(4, $content->total());
        $pool = $content->pool();
        self::assertInstanceOf(CheckPoolContent::class, $pool);
        self::assertSame('bestialFailure', $pool->special, 'The first roll keeps its own.');
        self::assertInstanceOf(CheckPushContent::class, $pool->push);
        self::assertSame(['pool', 'pool', 'pool', 'hunger', 'hunger'], array_column($pool->push->dice, 'group'));
        self::assertSame([true, true, false, false, false], array_column($pool->push->dice, 'rerolled'));
        self::assertEquals($content, CheckContent::fromArray($content->toArray()));
    }

    #[Test]
    public function aPushThatLeavesNoSpecialResultReadsNoneNotTheFirstRollsOne(): void
    {
        $content = $this->pushedContent($this->content([2, 3, 7, 1, 3]), [7, 7]);

        self::assertNull($content->special(), 'The roll wins now, so the Hunger 1 means nothing.');
        self::assertSame('bestialFailure', $content->pool()?->special);
    }

    #[Test]
    public function anEntryStoredBeforeHungerDiceReadsUnchangedWithNoSpecialResult(): void
    {
        $legacy = CheckPoolContentTest::sneak()->toArray();
        self::assertArrayNotHasKey('criticalsBonus', self::poolOf($legacy));
        self::assertArrayNotHasKey('special', self::poolOf($legacy));

        $content = CheckContent::fromArray($legacy);

        self::assertNull($content->special());
        self::assertSame(0, $content->pool()?->criticalsBonus);
        self::assertSame(2, $content->total());
        self::assertSame($legacy, $content->toArray(), 'Reading and writing it again adds nothing.');
    }

    #[Test]
    public function aLegacyPushedEntryReadsUnchangedToo(): void
    {
        $legacy = CheckPushContentTest::pushedSneak()->toArray();

        $content = CheckContent::fromArray($legacy);

        self::assertNull($content->special());
        self::assertSame($legacy, $content->toArray());
    }

    /**
     * @return iterable<string, array{callable(array<mixed>): array<mixed>, string}> a change to the stored entry and the refusal
     */
    public static function malformed(): iterable
    {
        yield 'an unknown special result' => [static fn (array $e): array => self::withPool($e, ['special' => 'brutal']), '"special" must be critical, messyCritical, bestialFailure or null'];
        yield 'a critical the dice cannot show' => [static fn (array $e): array => self::withPool($e, ['special' => 'critical', 'criticalsBonus' => 0]), '"special" must be a special result the dice can show, not "critical"'];
        yield 'a bestial failure without a Hunger 1' => [static fn (array $e): array => self::withPool($e, ['special' => 'bestialFailure']), '"special" must be a special result the dice can show, not "bestialFailure"'];
        yield 'a bonus over 10' => [static fn (array $e): array => self::withPool($e, ['criticalsBonus' => 11]), '"criticalsBonus" must be between 0 and 10'];
        yield 'a total that ignores the critical pairs' => [static fn (array $e): array => ['total' => 2, 'diceTotal' => 2] + $e, '"total" must be the successes of the pool, plus the bonus of its critical pairs'];
        yield 'a Hunger die before a regular die' => [static fn (array $e): array => self::withPool($e, ['dice' => array_replace(self::diceOf(self::poolOf($e)), [0 => ['group' => 'hunger', 'face' => 10, 'success' => true]])]), '"group" must be Hunger dice after the regular dice'];
    }

    /**
     * @param callable(array<mixed>): array<mixed> $change
     */
    #[Test]
    #[DataProvider('malformed')]
    public function aMalformedHungerEntryIsRejected(callable $change, string $message): void
    {
        $this->expectException(InvalidJournalEntryContent::class);
        $this->expectExceptionMessageIsOrContains($message);

        CheckContent::fromArray($change(self::content([10, 2, 2, 10, 3])->toArray()));
    }

    /**
     * @return iterable<string, array{callable(array<mixed>): array<mixed>, string}> a change to the pushed entry's push part and the refusal
     */
    public static function malformedPushes(): iterable
    {
        yield 'a Hunger die re-rolled' => [static fn (array $e): array => self::withDie($e, 3, ['rerolled' => true, 'face' => 3, 'success' => false]), '"push.rerolled" must be false for a die that succeeded or a Hunger die, which a push keeps'];
        yield 'a Hunger die changing its face' => [static fn (array $e): array => self::withDie($e, 3, ['face' => 3, 'success' => false]), '"push.face" must be the face of a die the push did not re-roll'];
        yield 'a die changing group' => [static fn (array $e): array => self::withDie($e, 2, ['group' => 'hunger']), '"push.group" must be the group of the die before the push'];
        yield 'a later failed die re-rolled while an earlier one was kept' => [static fn (array $e): array => self::withDie(self::withDie($e, 0, ['rerolled' => false, 'face' => 2, 'success' => false]), 2, ['rerolled' => true, 'face' => 10, 'success' => true]), '"push.rerolled" must be the first failed regular dice in roll order'];
        yield 'a special result the pushed dice cannot show' => [static fn (array $e): array => self::withPush($e, ['special' => 'messyCritical']), '"push.special" must be a special result the dice can show, not "messyCritical"'];
    }

    /**
     * @param callable(array<mixed>): array<mixed> $change
     */
    #[Test]
    #[DataProvider('malformedPushes')]
    public function aMalformedHungerPushIsRejected(callable $change, string $message): void
    {
        $this->expectException(InvalidJournalEntryContent::class);
        $this->expectExceptionMessageIsOrContains($message);

        CheckContent::fromArray($change(self::pushedContent(self::content([2, 3, 2, 1, 3]), [10, 10])->toArray()));
    }

    /**
     * @param array<mixed> $entry
     *
     * @return array<mixed>
     */
    private static function poolOf(array $entry): array
    {
        $pool = $entry['pool'] ?? null;
        self::assertIsArray($pool);

        /* @var array<string, mixed> $pool */
        return $pool;
    }

    /**
     * @param array<mixed> $part a pool or push part
     *
     * @return list<array<string, mixed>>
     */
    private static function diceOf(array $part): array
    {
        /** @var list<array<string, mixed>> $dice */
        $dice = $part['dice'];

        return $dice;
    }

    /**
     * @param array<mixed>         $entry
     * @param array<string, mixed> $changes
     *
     * @return array<mixed>
     */
    private static function withPool(array $entry, array $changes): array
    {
        $entry['pool'] = $changes + self::poolOf($entry);

        return $entry;
    }

    /**
     * @param array<mixed>         $entry
     * @param array<string, mixed> $changes
     *
     * @return array<mixed>
     */
    private static function withPush(array $entry, array $changes): array
    {
        $pool = self::poolOf($entry);
        $push = $pool['push'];
        self::assertIsArray($push);
        $pool['push'] = $changes + $push;
        $entry['pool'] = $pool;

        return $entry;
    }

    /**
     * @param array<mixed>         $entry
     * @param array<string, mixed> $changes
     *
     * @return array<mixed>
     */
    private static function withDie(array $entry, int $index, array $changes): array
    {
        $push = self::poolOf($entry)['push'];
        self::assertIsArray($push);
        $dice = self::diceOf($push);
        $dice[$index] = $changes + $dice[$index];

        return self::withPush($entry, ['dice' => $dice]);
    }
}
