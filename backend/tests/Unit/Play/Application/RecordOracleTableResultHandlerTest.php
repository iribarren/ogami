<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignNotFound;
use App\Play\Application\JournalEntryNotFound;
use App\Play\Application\RecordOracleTableResult;
use App\Play\Application\RecordOracleTableResultHandler;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\NoCurrentScene;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\TableEntryMetadata;
use App\Play\Domain\GameSystem\UnknownGameSystemOracle;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Play\Domain\Journal\InvalidJournalEntryId;
use App\Play\Domain\Journal\JournalEntry;
use App\Play\Domain\Journal\JournalEntryId;
use App\Play\Domain\Journal\LikelihoodContent;
use App\Play\Domain\Journal\NotARandomEventQuestion;
use App\Play\Domain\Journal\NoteContent;
use App\Play\Domain\Journal\OracleTableContent;
use App\Play\Domain\Journal\RandomEventAlreadyRolled;
use App\Randomness\Domain\Oracle\OracleTableSet;
use App\Tests\Support\Play\UnreadablePublishedGameSystemReleases;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(RecordOracleTableResult::class)]
#[CoversClass(RecordOracleTableResultHandler::class)]
final class RecordOracleTableResultHandlerTest extends JournalTestCase
{
    #[Test]
    public function itRollsOnTheTableOfThePinnedReleaseAndRecordsEveryStep(): void
    {
        // Weather rolls 5 (Storm), which nests storm-kind, which rolls 2 (Hail). Release v2 has no
        // oracles, so a result proves the pinned v1 was asked.
        $handler = new RecordOracleTableResultHandler($this->journal, new ScriptedRandomNumberGenerator(5, 2));

        $handler(new RecordOracleTableResult('entry-1', 'campaign-1', 'user-1', 'weather'));

        $entry = $this->onlyEntryOf('campaign-1');
        self::assertSame('entry-1', $entry->id()->toString());
        self::assertSame(1, $entry->sessionNumber());
        self::assertSame(2, $entry->sceneNumber());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $entry->recordedAt());
        self::assertSame([
            'kind' => 'oracle-table',
            'oracleKey' => 'weather',
            'oracleName' => 'Weather',
            'forEntry' => null,
            'steps' => [
                ['tableKey' => 'weather', 'tableName' => 'Weather', 'dice' => '1d6', 'total' => 5, 'text' => 'Storm', 'nestedTableKey' => 'storm-kind', 'sceneType' => null],
                ['tableKey' => 'storm-kind', 'tableName' => 'Storm kind', 'dice' => '1d2', 'total' => 2, 'text' => 'Hail', 'nestedTableKey' => null, 'sceneType' => null],
            ],
        ], $entry->content()->toArray());
    }

    #[Test]
    public function itStoresTheSceneTypeTheRolledEntryOffers(): void
    {
        $tables = OracleTableSet::fromArray([['key' => 'scenes', 'name' => 'Scenes', 'dice' => '1d6', 'entries' => [
            ['min' => 1, 'max' => 3, 'text' => 'Quiet'],
            ['min' => 4, 'max' => 6, 'text' => 'Chase'],
        ]]]);
        $this->releases->add(new GameSystemSnapshot('heist', 'Heist', 1, $tables, [], [], tableEntries: [
            'scenes' => [new TableEntryMetadata('quiet', null, []), new TableEntryMetadata('chase', 'getaway', [])],
        ]));
        $campaign = Campaign::create(CampaignId::fromString('campaign-3'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-05T10:00:00+00:00'));
        $campaign->startSession(new \DateTimeImmutable('2026-10-06T09:00:00+00:00'));
        $campaign->startScene('The roofs', new \DateTimeImmutable('2026-10-06T09:05:00+00:00'));
        $this->campaigns->add($campaign);

        foreach ([2, 5] as $index => $roll) {
            (new RecordOracleTableResultHandler($this->journal, new ScriptedRandomNumberGenerator($roll)))(new RecordOracleTableResult('entry-'.$index, 'campaign-3', 'user-1', 'scenes'));
        }

        $stored = array_map(static fn (JournalEntry $entry): ?string => $entry->content() instanceof OracleTableContent ? $entry->content()->steps()[0]['sceneType'] : 'not an oracle table', $this->journalOf('campaign-3'));
        self::assertSame([null, 'getaway'], $stored);
    }

    #[Test]
    public function aRollForARandomEventIsTiedToItsQuestion(): void
    {
        $this->givenQuestion('question-1', 'weather');

        (new RecordOracleTableResultHandler($this->journal, new ScriptedRandomNumberGenerator(1)))(new RecordOracleTableResult('entry-1', 'campaign-1', 'user-1', 'weather', 'question-1'));

        $content = $this->journalOf('campaign-1')[0]->content();
        self::assertInstanceOf(OracleTableContent::class, $content);
        self::assertSame('question-1', $content->forEntry());
    }

    #[Test]
    public function aBlankQuestionIdIsRefusedAndNothingIsRecorded(): void
    {
        $this->assertRefused(InvalidJournalEntryId::class, ' ');
    }

    #[Test]
    public function aQuestionNoEntryHasIsNotFoundAndNothingIsRecorded(): void
    {
        $this->assertRefused(JournalEntryNotFound::class, 'no-such-entry');
    }

    #[Test]
    public function aQuestionOfAnotherCampaignIsNotFound(): void
    {
        $this->givenQuestion('question-1', 'weather');
        $this->entries->add(JournalEntry::reconstitute(JournalEntryId::fromString('other-1'), CampaignId::fromString('campaign-2'), 1, 1, new \DateTimeImmutable(self::NOW), $this->likelihood('weather')));

        $this->assertRefused(JournalEntryNotFound::class, 'other-1');
    }

    #[Test]
    public function anEntryThatIsNotALikelihoodAnswerIsNotAQuestion(): void
    {
        $this->entries->add($this->journal->entry('note-1', $this->ownedCampaigns->get('campaign-1', 'user-1'), NoteContent::of('A note'), new \DateTimeImmutable(self::NOW)));

        $this->assertRefused(NotARandomEventQuestion::class, 'note-1');
    }

    #[Test]
    public function aLikelihoodAnswerWithNoRandomEventOrAnotherOneIsNotAQuestionForThisTable(): void
    {
        $this->givenQuestion('plain-1', null);
        $this->givenQuestion('other-event-1', 'storm-kind');

        $this->assertRefused(NotARandomEventQuestion::class, 'plain-1');
        $this->assertRefused(NotARandomEventQuestion::class, 'other-event-1');
    }

    #[Test]
    public function aQuestionWhoseEventWasRolledCannotBeRolledAgain(): void
    {
        $this->givenQuestion('question-1', 'weather');
        $handler = new RecordOracleTableResultHandler($this->journal, new ScriptedRandomNumberGenerator(1, 2));
        $handler(new RecordOracleTableResult('entry-1', 'campaign-1', 'user-1', 'weather', 'question-1'));

        try {
            $handler(new RecordOracleTableResult('entry-2', 'campaign-1', 'user-1', 'weather', 'question-1'));
            self::fail('A random event was rolled twice.');
        } catch (RandomEventAlreadyRolled $exception) {
            self::assertSame('The random event of journal entry "question-1" was already rolled.', $exception->getMessage());
            self::assertCount(2, $this->journalOf('campaign-1'));
        }
    }

    #[Test]
    public function aRollNotForARandomEventIgnoresTheQuestionsOfTheJournal(): void
    {
        $this->givenQuestion('question-1', 'weather');
        $handler = new RecordOracleTableResultHandler($this->journal, new ScriptedRandomNumberGenerator(1, 2));

        $handler(new RecordOracleTableResult('entry-1', 'campaign-1', 'user-1', 'weather'));
        $handler(new RecordOracleTableResult('entry-2', 'campaign-1', 'user-1', 'weather'));

        self::assertCount(3, $this->journalOf('campaign-1'));
    }

    #[Test]
    public function anOracleTableNotInThePinnedReleaseIsUnknownAndNothingIsRecorded(): void
    {
        $handler = new RecordOracleTableResultHandler($this->journal, new ScriptedRandomNumberGenerator(1));

        try {
            $handler(new RecordOracleTableResult('entry-1', 'campaign-1', 'user-1', 'treasure'));
            self::fail('An unknown oracle table was rolled.');
        } catch (UnknownGameSystemOracle $exception) {
            self::assertSame('GameSystem "free-journal" v1 has no oracle table "treasure".', $exception->getMessage());
            self::assertSame([], $this->journalOf('campaign-1'));
        }
    }

    #[Test]
    public function anotherPlayersCampaignIsNotFound(): void
    {
        $handler = new RecordOracleTableResultHandler($this->journal, new ScriptedRandomNumberGenerator(1));

        $this->expectException(CampaignNotFound::class);

        $handler(new RecordOracleTableResult('entry-1', 'campaign-1', 'user-2', 'weather'));
    }

    #[Test]
    public function aCampaignWithoutCurrentSceneRecordsNothing(): void
    {
        $handler = new RecordOracleTableResultHandler($this->journal, new ScriptedRandomNumberGenerator(1));

        try {
            $handler(new RecordOracleTableResult('entry-1', 'campaign-2', 'user-1', 'weather'));
            self::fail('An oracle result was recorded without a scene.');
        } catch (NoCurrentScene) {
            self::assertSame([], $this->journalOf('campaign-2'));
        }
    }

    #[Test]
    #[DataProviderExternal(UnreadablePublishedGameSystemReleases::class, 'errors')]
    public function aPinnedReleaseThatCannotBeReadSurfacesThePlayErrorAndRecordsNothing(GameSystemReleaseNotFound|InvalidGameSystemRelease|UnsupportedReleaseSchemaVersion $error): void
    {
        $journal = $this->journalReadingFrom(new UnreadablePublishedGameSystemReleases($error));
        $handler = new RecordOracleTableResultHandler($journal, new ScriptedRandomNumberGenerator(1));

        try {
            $handler(new RecordOracleTableResult('entry-1', 'campaign-1', 'user-1', 'weather'));
            self::fail('An oracle table was rolled without its pinned release.');
        } catch (GameSystemReleaseNotFound|InvalidGameSystemRelease|UnsupportedReleaseSchemaVersion $exception) {
            self::assertSame($error, $exception);
            self::assertSame([], $this->journalOf('campaign-1'));
        }
    }

    /**
     * @param class-string<\Throwable> $error
     */
    private function assertRefused(string $error, string $forEntry): void
    {
        $before = $this->journalOf('campaign-1');

        try {
            (new RecordOracleTableResultHandler($this->journal, new ScriptedRandomNumberGenerator(1)))(new RecordOracleTableResult('entry-9', 'campaign-1', 'user-1', 'weather', $forEntry));
            self::fail('A roll was recorded for a question that is none.');
        } catch (\Throwable $exception) {
            self::assertInstanceOf($error, $exception);
            self::assertEquals($before, $this->journalOf('campaign-1'));
        }
    }

    /**
     * Records a fate question of campaign 1 whose answer was the random event of that table.
     */
    private function givenQuestion(string $id, ?string $randomEvent): void
    {
        $this->entries->add($this->journal->entry($id, $this->ownedCampaigns->get('campaign-1', 'user-1'), $this->likelihood($randomEvent), new \DateTimeImmutable(self::NOW)));
    }

    private function likelihood(?string $randomEvent): LikelihoodContent
    {
        return LikelihoodContent::fromArray([
            'oracleKey' => 'fate',
            'oracleName' => 'Fate question',
            'question' => 'Is the gate open?',
            'answer' => 'yes',
            'roll' => 22,
            'sides' => 100,
            'effectiveTarget' => 50,
            'likelihood' => 'even',
            'likelihoodLabel' => '50/50',
            'chaosFactor' => 5,
            'randomEvent' => $randomEvent,
        ]);
    }
}
