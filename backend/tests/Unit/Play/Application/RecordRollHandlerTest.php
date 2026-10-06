<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignNotFound;
use App\Play\Application\RecordRoll;
use App\Play\Application\RecordRollHandler;
use App\Play\Domain\Campaign\NoCurrentScene;
use App\Randomness\Domain\InvalidDiceExpression;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(RecordRoll::class)]
#[CoversClass(RecordRollHandler::class)]
final class RecordRollHandlerTest extends JournalTestCase
{
    #[Test]
    public function itRollsOnTheServerAndRecordsTheRollInTheCurrentScene(): void
    {
        $handler = new RecordRollHandler($this->journal, new ScriptedRandomNumberGenerator(6, 2, 5, 3));

        $handler(new RecordRoll('entry-1', 'campaign-1', 'user-1', '4d6kh3+1'));

        $entry = $this->onlyEntryOf('campaign-1');
        self::assertSame('entry-1', $entry->id()->toString());
        self::assertSame(1, $entry->sessionNumber());
        self::assertSame(2, $entry->sceneNumber());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $entry->recordedAt());
        self::assertSame([
            'kind' => 'roll',
            'expression' => '4d6kh3+1',
            'total' => 15,
            'groups' => [
                ['notation' => '4d6kh3', 'sides' => 6, 'dice' => [
                    ['value' => 6, 'kept' => true],
                    ['value' => 2, 'kept' => false],
                    ['value' => 5, 'kept' => true],
                    ['value' => 3, 'kept' => true],
                ], 'subtotal' => 14],
            ],
        ], $entry->content()->toArray());
    }

    #[Test]
    public function anInvalidExpressionRecordsNothing(): void
    {
        $handler = new RecordRollHandler($this->journal, new ScriptedRandomNumberGenerator());

        try {
            $handler(new RecordRoll('entry-1', 'campaign-1', 'user-1', '2d'));
            self::fail('An invalid expression was rolled.');
        } catch (InvalidDiceExpression) {
            self::assertSame([], $this->journalOf('campaign-1'));
        }
    }

    #[Test]
    public function anotherPlayersCampaignIsNotFound(): void
    {
        $handler = new RecordRollHandler($this->journal, new ScriptedRandomNumberGenerator(3));

        $this->expectException(CampaignNotFound::class);

        $handler(new RecordRoll('entry-1', 'campaign-1', 'user-2', '1d6'));
    }

    #[Test]
    public function aCampaignWithoutCurrentSceneRecordsNothing(): void
    {
        $handler = new RecordRollHandler($this->journal, new ScriptedRandomNumberGenerator(3));

        try {
            $handler(new RecordRoll('entry-1', 'campaign-2', 'user-1', '1d6'));
            self::fail('A roll was recorded without a scene.');
        } catch (NoCurrentScene) {
            self::assertSame([], $this->journalOf('campaign-2'));
        }
    }
}
