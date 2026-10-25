<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Journal;

use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\FlowRun\SheetChange;
use App\Play\Domain\GameSystem\Check\CheckBand;
use App\Play\Domain\GameSystem\Check\PushResult;
use App\Play\Domain\Journal\CheckAlreadyPushed;
use App\Play\Domain\Journal\CheckContent;
use App\Play\Domain\Journal\CheckPoolContent;
use App\Play\Domain\Journal\CheckPushContent;
use App\Play\Domain\Journal\InvalidJournalEntryContent;
use App\Play\Domain\Journal\JournalEntry;
use App\Play\Domain\Journal\JournalEntryContents;
use App\Play\Domain\Journal\JournalEntryId;
use App\Randomness\Domain\DicePool;
use App\Randomness\Domain\PoolRoll;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The push part of a pool check entry (ADR 0041), on the roll of CheckPoolContentTest::sneak() (3d6,
 * success on 5+: 6, 2, 5, two successes, the band "hit"). Pushed, the 2 becomes a 5: three successes
 * and the band "great", and the cost marked a point of "stress" (1 to 2).
 */
#[CoversClass(CheckPushContent::class)]
#[CoversClass(CheckPoolContent::class)]
#[CoversClass(CheckContent::class)]
#[CoversClass(JournalEntry::class)]
#[CoversClass(CheckAlreadyPushed::class)]
final class CheckPushContentTest extends TestCase
{
    public static function pushResult(): PushResult
    {
        $roll = PoolRoll::ofFaces(new DicePool(3, 6, 5), [6, 2, 5])->push(new ScriptedRandomNumberGenerator(5));

        return new PushResult($roll, $roll->successes(), new CheckBand('great', 'Great'));
    }

    public static function pushedSneak(): CheckContent
    {
        return CheckPoolContentTest::sneak()->pushed('Take a point of stress', self::pushResult(), [new SheetChange('stress', 1, 2)]);
    }

    #[Test]
    public function pushingKeepsTheFirstRollAndAddsThePushWithTheNewTotals(): void
    {
        $unpushed = CheckPoolContentTest::sneak()->toArray();
        $pushed = self::pushedSneak()->toArray();

        self::assertSame([3, 3, 'great', 'Great'], [$pushed['diceTotal'], $pushed['total'], $pushed['bandKey'], $pushed['bandLabel']]);
        self::assertSame($unpushed['pool']['dice'] ?? null, $pushed['pool']['dice'] ?? null);
        self::assertSame(2, $pushed['pool']['successes'] ?? null);
        self::assertSame([
            'label' => 'Take a point of stress',
            'dice' => [
                ['group' => 'pool', 'face' => 6, 'success' => true, 'rerolled' => false],
                ['group' => 'pool', 'face' => 5, 'success' => true, 'rerolled' => true],
                ['group' => 'pool', 'face' => 5, 'success' => true, 'rerolled' => false],
            ],
            'successes' => 3,
            'rolledBandKey' => 'hit',
            'rolledBandLabel' => 'Hit',
            'changes' => [['field' => 'stress', 'before' => 1, 'after' => 2, 'dice' => null]],
        ], $pushed['pool']['push'] ?? null);
        self::assertSame(array_diff_key($unpushed, ['diceTotal' => 0, 'total' => 0, 'bandKey' => 0, 'bandLabel' => 0, 'pool' => 0]), array_diff_key($pushed, ['diceTotal' => 0, 'total' => 0, 'bandKey' => 0, 'bandLabel' => 0, 'pool' => 0]));
    }

    #[Test]
    public function anUnpushedEntryStoresNoPushKey(): void
    {
        self::assertArrayNotHasKey('push', CheckPoolContentTest::sneak()->toArray()['pool'] ?? []);
        self::assertNull(CheckPoolContentTest::sneak()->pool()?->push);
    }

    #[Test]
    public function aPushedEntryRoundTripsThroughItsArray(): void
    {
        $content = self::pushedSneak();

        self::assertEquals($content, JournalEntryContents::fromArray($content->toArray()));
    }

    #[Test]
    public function aRollIsPushedOnce(): void
    {
        $this->expectException(CheckAlreadyPushed::class);

        self::pushedSneak()->pushed('Again', self::pushResult(), []);
    }

    #[Test]
    public function aDiceCheckCannotBePushed(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('only a pool roll can be pushed');

        CheckContentTest::edgeCheck()->pushed('Push', self::pushResult(), []);
    }

    #[Test]
    public function theEntryTakesItsPushedContentOnceAndNothingElseChanges(): void
    {
        $entry = $this->entry(CheckPoolContentTest::sneak());
        $pushed = $entry->pushedWith(self::pushedSneak());

        self::assertEquals(self::pushedSneak(), $pushed->content());
        self::assertSame([$entry->id()->toString(), $entry->sessionNumber(), $entry->sceneNumber(), $entry->recordedAt()], [$pushed->id()->toString(), $pushed->sessionNumber(), $pushed->sceneNumber(), $pushed->recordedAt()]);
        self::assertNull($entry->content() instanceof CheckContent ? $entry->content()->pool()?->push : 'not a check', 'The entry it was made from is left as it was.');

        $this->expectException(CheckAlreadyPushed::class);

        $pushed->pushedWith(self::pushedSneak());
    }

    #[Test]
    public function anEntryThatIsNotAPoolCheckOrWithoutAPushedContentIsRefused(): void
    {
        foreach ([[CheckContentTest::edgeCheck(), self::pushedSneak()], [CheckPoolContentTest::sneak(), CheckPoolContentTest::sneak()]] as [$current, $given]) {
            try {
                $this->entry($current)->pushedWith($given);
                self::fail('The change is not a push of a pool check.');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * @param array<string, mixed> $change
     *
     * @return array<string, mixed> the pushed entry's stored array with the change applied to its push part
     */
    private static function withPush(array $change): array
    {
        $stored = self::pushedSneak()->toArray();
        $pool = $stored['pool'] ?? [];
        $push = $pool['push'] ?? [];

        return ['pool' => ['push' => $change + $push] + $pool] + $stored;
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function malformedPushes(): iterable
    {
        $stored = self::pushedSneak()->toArray();
        $die = static fn (int $face, bool $success, bool $rerolled): array => ['group' => 'pool', 'face' => $face, 'success' => $success, 'rerolled' => $rerolled];

        yield 'a push that is no object' => [['pool' => ['push' => 'yes'] + ($stored['pool'] ?? [])] + $stored, '"push" must be an object or null'];
        yield 'a blank label' => [self::withPush(['label' => ' ']), '"label" must be 1 to 100 characters'];
        yield 'a label over 100 characters' => [self::withPush(['label' => str_repeat('a', 101)]), '"label" must be 1 to 100 characters'];
        yield 'a blank rolled band key' => [self::withPush(['rolledBandKey' => ' ']), 'A rolled band key must not be blank.'];
        yield 'a die missing' => [self::withPush(['dice' => [$die(6, true, false), $die(5, true, true)], 'successes' => 2]) + ['total' => 2, 'diceTotal' => 2], '"push.dice" must be as many dice as the pool rolled'];
        yield 'a die added' => [self::withPush(['dice' => [$die(6, true, false), $die(5, true, true), $die(5, true, false), $die(5, true, false)], 'successes' => 4]), '"push.dice" must be as many dice as the pool rolled'];
        yield 'a die of another group' => [self::withPush(['dice' => [['group' => 'hunger'] + $die(6, true, false), $die(5, true, true), $die(5, true, false)]]), '"push.group" must be "pool"'];
        yield 'a face over the sides' => [self::withPush(['dice' => [$die(6, true, false), $die(7, true, true), $die(5, true, false)]]), '"push.face" must be between 1 and the sides'];
        yield 'a success that disagrees with its face' => [self::withPush(['dice' => [$die(6, true, false), $die(4, true, true), $die(5, true, false)]]), '"push.success" must be whether the face reaches the success threshold'];
        yield 'a die that succeeded and was re-rolled' => [self::withPush(['dice' => [$die(6, true, true), $die(5, true, true), $die(5, true, false)]]), '"push.rerolled" must be true for a die that failed before the push and false for one that succeeded'];
        yield 'a die that failed and was not re-rolled' => [self::withPush(['dice' => [$die(6, true, false), $die(2, false, false), $die(5, true, false)], 'successes' => 2]) + ['total' => 2, 'diceTotal' => 2], '"push.rerolled" must be true for a die that failed before the push and false for one that succeeded'];
        yield 'a die that succeeded and changed its face' => [self::withPush(['dice' => [$die(5, true, false), $die(5, true, true), $die(5, true, false)]]), '"push.face" must be the face of a die that succeeded before the push'];
        yield 'successes that are not those of the dice' => [self::withPush(['successes' => 2]) + ['total' => 2, 'diceTotal' => 2], '"successes" must be the number of dice that succeeded after the push'];
        yield 'a total that is not the pushed successes' => [['total' => 2] + $stored, '"total" must be the successes of the pool after the push'];
        yield 'a dice total that is not the pushed successes' => [['diceTotal' => 2] + $stored, '"total" must be the successes of the pool after the push'];
        yield 'a change that is not an object' => [self::withPush(['changes' => ['stress']]), '"changes" must be a list of objects'];
        yield 'a change with a blank field' => [self::withPush(['changes' => [['field' => ' ', 'before' => 1, 'after' => 2, 'dice' => null]]]), 'A sheet field must not be blank.'];
        yield 'a change without its dice' => [self::withPush(['changes' => [['field' => 'stress', 'before' => 1, 'after' => 2]]]), '"dice" must be {expression, total} or null'];
    }

    /**
     * @param array<mixed> $data
     */
    #[Test]
    #[DataProvider('malformedPushes')]
    public function aMalformedOrContradictoryPushIsRejected(array $data, string $message): void
    {
        $this->expectException(InvalidJournalEntryContent::class);
        $this->expectExceptionMessageIsOrContains($message);

        JournalEntryContents::fromArray($data);
    }

    #[Test]
    public function aPushThatRerolledNoDieIsRejected(): void
    {
        // Every die succeeded before the push: nothing could have been re-rolled.
        $stored = CheckPoolContentTest::sneak()->toArray();
        $pool = $stored['pool'] ?? [];
        $die = static fn (int $face): array => ['group' => 'pool', 'face' => $face, 'success' => true];
        $dice = [$die(6), $die(5), $die(5)];
        $push = ['label' => 'Push', 'dice' => array_map(static fn (array $d): array => $d + ['rerolled' => false], $dice), 'successes' => 3, 'rolledBandKey' => 'hit', 'rolledBandLabel' => 'Hit', 'changes' => []];

        $this->expectException(InvalidJournalEntryContent::class);
        $this->expectExceptionMessageIsOrContains('"push" must be a push that re-rolled at least one die');

        JournalEntryContents::fromArray(['total' => 3, 'diceTotal' => 3, 'pool' => ['dice' => $dice, 'successes' => 3, 'push' => $push] + $pool] + $stored);
    }

    private function entry(CheckContent $content): JournalEntry
    {
        return JournalEntry::reconstitute(JournalEntryId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f8001'), CampaignId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f5001'), 1, 2, new \DateTimeImmutable('2026-10-25T10:00:00+00:00'), $content);
    }
}
