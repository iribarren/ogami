<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Application;

use App\Randomness\Application\AskLikelihoodOracle;
use App\Randomness\Application\OracleTableResultView;
use App\Randomness\Application\ResolveOracleTable;
use App\Randomness\Domain\Oracle\InvalidLikelihoodOracle;
use App\Randomness\Domain\Oracle\InvalidOracleTable;
use App\Studio\Application\LikelihoodTestView;
use App\Studio\Application\OwnedGameSystems;
use App\Studio\Application\StoredOracles;
use App\Studio\Application\TestAskLikelihoodOracle;
use App\Studio\Application\TestAskLikelihoodOracleHandler;
use App\Studio\Application\TestRollOracleTable;
use App\Studio\Application\TestRollOracleTableHandler;
use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\GameSystemId;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\GameSystem\OracleNotFound;
use App\Studio\Domain\Release\ReleaseContent;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use App\Tests\Support\Studio\InMemoryGameSystemRepository;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TestRollOracleTableHandler::class)]
#[CoversClass(TestAskLikelihoodOracleHandler::class)]
#[CoversClass(StoredOracles::class)]
final class GameSystemOracleTestRollsTest extends TestCase
{
    private InMemoryGameSystemRepository $gameSystems;
    private OwnedGameSystems $owned;

    protected function setUp(): void
    {
        $this->gameSystems = new InMemoryGameSystemRepository();
        $this->owned = new OwnedGameSystems($this->gameSystems);
    }

    #[Test]
    public function aTableRollFollowsNestedTablesOfTheStoredDraft(): void
    {
        $this->draft('my-heist', 'valid/v2-every-part');

        // "complications" is weighted 1d4 and its last entry nests "escape-routes" (1d3, "Sewers" last).
        $result = $this->roll(new ScriptedRandomNumberGenerator(4, 3), 'ann', 'my-heist', 'complications');

        self::assertSame('complications', $result->table);
        self::assertSame(['complications', 'escape-routes'], array_column($result->steps, 'tableKey'));
        self::assertSame(['', 'Sewers'], array_column($result->steps, 'text'));
        self::assertSame(['1d4', '1d3'], array_column($result->steps, 'dice'));
    }

    #[Test]
    public function aTableRollOnAVersion1DraftWorks(): void
    {
        $this->draft('my-journal', 'valid/contract-doc-example');

        $result = $this->roll(new ScriptedRandomNumberGenerator(5, 1), 'ann', 'my-journal', 'weather');

        self::assertSame(['Storm', 'Rain'], array_column($result->steps, 'text'));
    }

    #[Test]
    public function aTableRollSendsTheWholeStoredSetAndNeverChangesTheDraft(): void
    {
        $draft = $this->draft('my-heist', 'valid/v2-every-part');
        $before = $draft->document();
        $bus = new RandomnessQueryBus(new ScriptedRandomNumberGenerator(1));

        (new TestRollOracleTableHandler($this->owned, $bus))(new TestRollOracleTable('ann', 'my-heist', 'heist-scenes'));

        self::assertCount(1, $bus->asked);
        self::assertInstanceOf(ResolveOracleTable::class, $bus->asked[0]);
        self::assertSame(['heist-scenes', 'complications', 'escape-routes'], array_column($bus->asked[0]->tables, 'key'));
        self::assertSame($before, $draft->document());
        self::assertSame(1, $draft->version());
    }

    #[Test]
    public function anUnknownTableIsNotFoundAndNothingIsRolled(): void
    {
        $this->draft('my-heist', 'valid/v2-every-part');
        $bus = new RandomnessQueryBus(new ScriptedRandomNumberGenerator());

        try {
            (new TestRollOracleTableHandler($this->owned, $bus))(new TestRollOracleTable('ann', 'my-heist', 'fate'));
            self::fail('Expected OracleNotFound');
        } catch (OracleNotFound) {
            self::assertSame([], $bus->asked);
        }
    }

    #[Test]
    public function aDraftWithoutAnOraclesSectionHasNoTableToRoll(): void
    {
        $draft = $this->draft('legacy', 'valid/v2-every-part');
        $draft->restore(ReleaseArrays::without($draft->document(), 'oracles'), new \DateTimeImmutable());

        $this->expectException(OracleNotFound::class);

        $this->roll(new ScriptedRandomNumberGenerator(), 'ann', 'legacy', 'complications');
    }

    #[Test]
    public function aTableSetRandomnessRefusesSurfacesItsError(): void
    {
        $draft = $this->draft('broken', 'valid/v2-every-part');
        $draft->restore(ReleaseArrays::with($draft->document(), 'oracles.tables.0.entries', [['min' => 1, 'max' => 2, 'text' => 'Gap']]), new \DateTimeImmutable());

        $this->expectException(InvalidOracleTable::class);

        $this->roll(new ScriptedRandomNumberGenerator(5), 'ann', 'broken', 'heist-scenes');
    }

    #[Test]
    public function aLikelihoodAnswerComesWithoutARandomEventWhenNoneIsSignalled(): void
    {
        $this->draft('my-heist', 'valid/v2-every-part');

        $result = $this->ask(new ScriptedRandomNumberGenerator(33), 'ann', 'my-heist', 'fate', 'even', 5);

        self::assertSame(['yes', 33, 100, 50, 'even'], [$result->answer->answer, $result->answer->roll, $result->answer->sides, $result->answer->effectiveTarget, $result->answer->likelihood]);
        self::assertNull($result->answer->randomEvent);
        self::assertNull($result->randomEvent);
    }

    #[Test]
    public function aSignalledRandomEventRollsTheEventTableToo(): void
    {
        $this->draft('my-fate', 'valid/v2-random-event');

        // 33 is a double whose digit (3) is within the chaos factor (5); the event table is weighted 1d2.
        $result = $this->ask(new ScriptedRandomNumberGenerator(33, 2), 'ann', 'my-fate', 'fate', 'even', 5);

        self::assertSame('focus', $result->answer->randomEvent);
        self::assertNotNull($result->randomEvent);
        self::assertSame(['focus', 'A stranger appears'], [$result->randomEvent->table, $result->randomEvent->steps[0]->text]);
    }

    #[Test]
    public function theNeutralChaosFactorIsUsedWhenNoneIsGiven(): void
    {
        $this->draft('my-fate', 'valid/v2-random-event');

        $result = $this->ask(new ScriptedRandomNumberGenerator(77), 'ann', 'my-fate', 'fate', 'even', null);

        self::assertSame(5, $result->answer->chaosFactor);
        self::assertNull($result->randomEvent);
    }

    #[Test]
    public function aLikelihoodTestSendsTheStoredOracleAndNeverChangesTheDraft(): void
    {
        $draft = $this->draft('my-fate', 'valid/v2-random-event');
        $before = $draft->document();
        $bus = new RandomnessQueryBus(new ScriptedRandomNumberGenerator(33, 1));

        (new TestAskLikelihoodOracleHandler($this->owned, $bus))(new TestAskLikelihoodOracle('ann', 'my-fate', 'fate', 'even', 5));

        self::assertInstanceOf(AskLikelihoodOracle::class, $bus->asked[0]);
        self::assertSame('fate', $bus->asked[0]->oracle['key']);
        self::assertCount(2, $bus->asked);
        self::assertSame($before, $draft->document());
        self::assertSame(1, $draft->version());
    }

    #[Test]
    public function anUnknownLikelihoodOracleIsNotFound(): void
    {
        $this->draft('my-heist', 'valid/v2-every-part');

        $this->expectException(OracleNotFound::class);

        $this->ask(new ScriptedRandomNumberGenerator(), 'ann', 'my-heist', 'complications', 'even', null);
    }

    #[Test]
    public function anUnknownLevelOrAnImpossibleChaosFactorSurfacesTheRandomnessError(): void
    {
        $this->draft('my-heist', 'valid/v2-every-part');

        foreach ([['certain', null], ['even', 12]] as [$level, $chaos]) {
            try {
                $this->ask(new ScriptedRandomNumberGenerator(1), 'ann', 'my-heist', 'fate', $level, $chaos);
                self::fail('Expected InvalidLikelihoodOracle');
            } catch (InvalidLikelihoodOracle $invalid) {
                self::assertNotSame('', $invalid->getMessage());
            }
        }
    }

    #[Test]
    public function testRollsAreOwnerOnly(): void
    {
        $this->draft('my-fate', 'valid/v2-random-event');
        $refused = [];

        foreach ([
            fn (): OracleTableResultView => $this->roll(new ScriptedRandomNumberGenerator(1), 'bob', 'my-fate', 'focus'),
            fn (): LikelihoodTestView => $this->ask(new ScriptedRandomNumberGenerator(33), 'bob', 'my-fate', 'fate', 'even', 5),
            fn (): OracleTableResultView => $this->roll(new ScriptedRandomNumberGenerator(1), 'ann', 'nothing', 'focus'),
        ] as $attempt) {
            try {
                $attempt();
            } catch (GameSystemNotFound) {
                $refused[] = true;
            }
        }

        self::assertCount(3, $refused);
    }

    private function roll(ScriptedRandomNumberGenerator $random, string $user, string $key, string $table): OracleTableResultView
    {
        return (new TestRollOracleTableHandler($this->owned, new RandomnessQueryBus($random)))(new TestRollOracleTable($user, $key, $table));
    }

    private function ask(ScriptedRandomNumberGenerator $random, string $user, string $key, string $oracle, string $level, ?int $chaos): LikelihoodTestView
    {
        return (new TestAskLikelihoodOracleHandler($this->owned, new RandomnessQueryBus($random)))(new TestAskLikelihoodOracle($user, $key, $oracle, $level, $chaos));
    }

    private function draft(string $key, string $fixture): GameSystem
    {
        $source = ReleaseContent::fromArray(ReleaseArrays::fixture($fixture))->toArray();
        $draft = GameSystem::createFromRelease(GameSystemId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a61'), $key, 'Draft', null, 'ann', $source, new \DateTimeImmutable('2026-10-16T10:00:00'));
        $this->gameSystems->add($draft);

        return $draft;
    }
}
