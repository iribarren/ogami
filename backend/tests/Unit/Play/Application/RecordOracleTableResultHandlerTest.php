<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignNotFound;
use App\Play\Application\RecordOracleTableResult;
use App\Play\Application\RecordOracleTableResultHandler;
use App\Play\Domain\Campaign\NoCurrentScene;
use App\Play\Domain\GameSystem\UnknownGameSystemOracle;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
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
                ['tableKey' => 'weather', 'tableName' => 'Weather', 'dice' => '1d6', 'total' => 5, 'text' => 'Storm', 'nestedTableKey' => 'storm-kind'],
                ['tableKey' => 'storm-kind', 'tableName' => 'Storm kind', 'dice' => '1d2', 'total' => 2, 'text' => 'Hail', 'nestedTableKey' => null],
            ],
        ], $entry->content()->toArray());
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
}
