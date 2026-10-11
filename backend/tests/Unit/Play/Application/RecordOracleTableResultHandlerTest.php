<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignNotFound;
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
use App\Play\Domain\Journal\JournalEntry;
use App\Play\Domain\Journal\OracleTableContent;
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
}
