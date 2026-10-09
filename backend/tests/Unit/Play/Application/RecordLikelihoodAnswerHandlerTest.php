<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignNotFound;
use App\Play\Application\RecordLikelihoodAnswer;
use App\Play\Application\RecordLikelihoodAnswerHandler;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\ChaosFactorBoundToTracker;
use App\Play\Domain\Campaign\NoCurrentScene;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnknownGameSystemOracle;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Play\Domain\Journal\InvalidJournalEntryContent;
use App\Randomness\Domain\Oracle\InvalidLikelihoodOracle;
use App\Tests\Support\Play\Snapshots;
use App\Tests\Support\Play\UnreadablePublishedGameSystemReleases;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
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

    #[Test]
    public function anOracleBoundToATrackerTakesTheCampaignValueAsItsChaosFactor(): void
    {
        $this->playOnAReleaseWithTrackers(chaos: 8);
        // "50/50" targets 50; chaos 8 is 3 above neutral, at 5 per point: 65. A roll of 60 is a yes.
        $handler = new RecordLikelihoodAnswerHandler($this->journal, new ScriptedRandomNumberGenerator(60));

        $handler(new RecordLikelihoodAnswer('entry-1', 'campaign-3', 'user-1', 'fate', 'even'));

        $content = $this->onlyEntryOf('campaign-3')->content()->toArray();
        self::assertSame(8, $content['chaosFactor']);
        self::assertSame(65, $content['effectiveTarget']);
        self::assertSame('yes', $content['answer']);
    }

    #[Test]
    public function anOracleBoundToATrackerRefusesAChaosFactorAndRecordsNothing(): void
    {
        $this->playOnAReleaseWithTrackers(chaos: 8);
        $handler = new RecordLikelihoodAnswerHandler($this->journal, new ScriptedRandomNumberGenerator(60));

        try {
            $handler(new RecordLikelihoodAnswer('entry-1', 'campaign-3', 'user-1', 'fate', 'even', 5));
            self::fail('A chaos factor was accepted for a bound oracle.');
        } catch (ChaosFactorBoundToTracker) {
            self::assertSame([], $this->journalOf('campaign-3'));
        }
    }

    #[Test]
    public function anUnboundOracleOfAReleaseWithTrackersTakesTheRequestedChaosFactor(): void
    {
        $this->playOnAReleaseWithTrackers(chaos: 8);
        $handler = new RecordLikelihoodAnswerHandler($this->journal, new ScriptedRandomNumberGenerator(60));

        $handler(new RecordLikelihoodAnswer('entry-1', 'campaign-3', 'user-1', 'omen', 'even', 2));

        self::assertSame(2, $this->onlyEntryOf('campaign-3')->content()->toArray()['chaosFactor']);
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

    #[Test]
    #[DataProviderExternal(UnreadablePublishedGameSystemReleases::class, 'errors')]
    public function aPinnedReleaseThatCannotBeReadSurfacesThePlayErrorAndRecordsNothing(GameSystemReleaseNotFound|InvalidGameSystemRelease|UnsupportedReleaseSchemaVersion $error): void
    {
        $journal = $this->journalReadingFrom(new UnreadablePublishedGameSystemReleases($error));
        $handler = new RecordLikelihoodAnswerHandler($journal, new ScriptedRandomNumberGenerator(30));

        try {
            $handler(new RecordLikelihoodAnswer('entry-1', 'campaign-1', 'user-1', 'fate', 'even'));
            self::fail('A likelihood oracle was asked without its pinned release.');
        } catch (GameSystemReleaseNotFound|InvalidGameSystemRelease|UnsupportedReleaseSchemaVersion $exception) {
            self::assertSame($error, $exception);
            self::assertSame([], $this->journalOf('campaign-1'));
        }
    }

    /**
     * "campaign-3" of "user-1" plays scene 1 of session 1 on release v1 of "heist", with trackers.
     */
    private function playOnAReleaseWithTrackers(int $chaos): void
    {
        $snapshot = Snapshots::withTrackers('heist', 'Heist', 1);
        $this->releases->add($snapshot);
        $campaign = Campaign::create(CampaignId::fromString('campaign-3'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-09T09:00:00+00:00'), $snapshot->trackers());
        $campaign->setTrackerValue($snapshot->tracker('chaos') ?? throw new \LogicException('No chaos tracker.'), $chaos);
        $campaign->startSession(new \DateTimeImmutable('2026-10-09T09:05:00+00:00'));
        $campaign->startScene('The vault', new \DateTimeImmutable('2026-10-09T09:06:00+00:00'));
        $this->campaigns->add($campaign);
    }
}
