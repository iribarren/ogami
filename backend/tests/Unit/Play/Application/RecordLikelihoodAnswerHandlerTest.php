<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignNotFound;
use App\Play\Application\RecordLikelihoodAnswer;
use App\Play\Application\RecordLikelihoodAnswerHandler;
use App\Play\Domain\Campaign\NoCurrentScene;
use App\Play\Domain\GameSystem\UnknownGameSystemOracle;
use App\Play\Domain\Journal\InvalidJournalEntryContent;
use App\Randomness\Domain\Oracle\InvalidLikelihoodOracle;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(RecordLikelihoodAnswer::class)]
#[CoversClass(RecordLikelihoodAnswerHandler::class)]
final class RecordLikelihoodAnswerHandlerTest extends JournalTestCase
{
    #[Test]
    public function itAsksTheOracleOfThePinnedReleaseWithTheChaosFactorAndRecordsTheAnswer(): void
    {
        // "Unlikely" targets 35; chaos 7 is 2 above neutral, at 5 per point: 45. A roll of 40 is a yes.
        $handler = new RecordLikelihoodAnswerHandler($this->journal, new ScriptedRandomNumberGenerator(40));

        $handler(new RecordLikelihoodAnswer('entry-1', 'campaign-1', 'user-1', 'fate', 'unlikely', 7, '  Is the gate open? '));

        $entry = $this->onlyEntryOf('campaign-1');
        self::assertSame('entry-1', $entry->id()->toString());
        self::assertSame(1, $entry->sessionNumber());
        self::assertSame(2, $entry->sceneNumber());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $entry->recordedAt());
        self::assertSame([
            'kind' => 'likelihood',
            'oracleKey' => 'fate',
            'oracleName' => 'Fate question',
            'question' => 'Is the gate open?',
            'answer' => 'yes',
            'roll' => 40,
            'sides' => 100,
            'effectiveTarget' => 45,
            'likelihood' => 'unlikely',
            'likelihoodLabel' => 'Unlikely',
            'chaosFactor' => 7,
        ], $entry->content()->toArray());
    }

    #[Test]
    public function withoutChaosFactorOrQuestionTheNeutralFactorIsUsed(): void
    {
        $handler = new RecordLikelihoodAnswerHandler($this->journal, new ScriptedRandomNumberGenerator(60));

        $handler(new RecordLikelihoodAnswer('entry-1', 'campaign-1', 'user-1', 'fate', 'even'));

        $content = $this->onlyEntryOf('campaign-1')->content()->toArray();
        self::assertNull($content['question']);
        self::assertSame('no', $content['answer']);
        self::assertSame(50, $content['effectiveTarget']);
        self::assertSame(5, $content['chaosFactor']);
    }

    /**
     * @return iterable<string, array{string, string, ?int, ?string, class-string<\Throwable>}>
     */
    public static function failingAsks(): iterable
    {
        yield 'oracle not in the pinned release' => ['luck', 'even', null, null, UnknownGameSystemOracle::class];
        yield 'unknown likelihood level' => ['fate', 'certain', null, null, InvalidLikelihoodOracle::class];
        yield 'chaos factor out of range' => ['fate', 'even', 10, null, InvalidLikelihoodOracle::class];
        yield 'chaos factor for an oracle without chaos' => ['plain', 'even', 5, null, InvalidLikelihoodOracle::class];
        yield 'question too long' => ['fate', 'even', null, str_repeat('a', 501), InvalidJournalEntryContent::class];
    }

    /**
     * @param class-string<\Throwable> $error
     */
    #[Test]
    #[DataProvider('failingAsks')]
    public function aFailingAskRecordsNothing(string $oracleKey, string $likelihood, ?int $chaosFactor, ?string $question, string $error): void
    {
        $handler = new RecordLikelihoodAnswerHandler($this->journal, new ScriptedRandomNumberGenerator(40));

        try {
            $handler(new RecordLikelihoodAnswer('entry-1', 'campaign-1', 'user-1', $oracleKey, $likelihood, $chaosFactor, $question));
            self::fail('A failing ask was recorded.');
        } catch (\Throwable $exception) {
            self::assertInstanceOf($error, $exception);
            self::assertSame([], $this->journalOf('campaign-1'));
        }
    }

    #[Test]
    public function anotherPlayersCampaignIsNotFound(): void
    {
        $handler = new RecordLikelihoodAnswerHandler($this->journal, new ScriptedRandomNumberGenerator(40));

        $this->expectException(CampaignNotFound::class);

        $handler(new RecordLikelihoodAnswer('entry-1', 'campaign-1', 'user-2', 'fate', 'even'));
    }

    #[Test]
    public function aCampaignWithoutCurrentSceneRecordsNothing(): void
    {
        $handler = new RecordLikelihoodAnswerHandler($this->journal, new ScriptedRandomNumberGenerator(40));

        try {
            $handler(new RecordLikelihoodAnswer('entry-1', 'campaign-2', 'user-1', 'fate', 'even'));
            self::fail('An answer was recorded without a scene.');
        } catch (NoCurrentScene) {
            self::assertSame([], $this->journalOf('campaign-2'));
        }
    }
}
