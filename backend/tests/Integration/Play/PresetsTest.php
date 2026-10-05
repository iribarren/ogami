<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play;

use App\Play\Application\GetGameSystemSnapshot;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Randomness\Domain\Oracle\InvalidLikelihoodOracle;
use App\Randomness\Domain\Oracle\LikelihoodAnswer;
use App\Randomness\Domain\Oracle\LikelihoodLevel;
use App\Randomness\Domain\Oracle\YesNoAnswer;
use App\Shared\Application\Bus\QueryBus;
use App\Studio\Infrastructure\Console\PublishGameSystemReleaseConsoleCommand;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The shipped presets publish through the console like `make presets`, and Play resolves every
 * oracle in their snapshots.
 */
#[CoversClass(PublishGameSystemReleaseConsoleCommand::class)]
final class PresetsTest extends KernelTestCase
{
    private const string PRESETS = __DIR__.'/../../../presets';

    private CommandTester $tester;

    protected function setUp(): void
    {
        $application = new Application(self::bootKernel());
        $this->tester = new CommandTester($application->find('app:gamesystem:publish'));
    }

    #[Test]
    public function freeJournalPublishesOnceAndResolvesItsOracles(): void
    {
        $this->assertPublishesOnce('free-journal');

        $snapshot = $this->snapshot('free-journal');
        self::assertSame('Free journal', $snapshot->name());
        self::assertSame(1, $snapshot->releaseVersion());
        self::assertSame([], $snapshot->flowSteps());

        self::assertSame(['action', 'theme', 'descriptor', 'place'], $snapshot->oracleTableKeys());
        $this->assertWeightedTableResolves($snapshot, 'action', 24, 'Seek', 'Rest');
        $this->assertWeightedTableResolves($snapshot, 'theme', 24, 'Loyalty', 'Homecoming');
        $this->assertWeightedTableResolves($snapshot, 'descriptor', 24, 'Rusted', 'Gilded');
        $this->assertWeightedTableResolves($snapshot, 'place', 24, 'A crossroads inn', 'A lighthouse with no keeper');

        self::assertSame(['yes-no'], $this->likelihoodKeys($snapshot));
        $yesNo = $snapshot->likelihoodOracle('yes-no');
        self::assertSame('Yes/no question', $yesNo->name());
        $oracle = $yesNo->oracle();
        self::assertNull($oracle->chaos());
        self::assertSame(['very-unlikely' => 10, 'unlikely' => 30, 'even' => 50, 'likely' => 70, 'very-likely' => 90], $this->targets($oracle->levels()));

        $this->assertAnswer(YesNoAnswer::ExceptionalYes, 70, $oracle->ask('likely', null, new ScriptedRandomNumberGenerator(7)));
        $this->assertAnswer(YesNoAnswer::Yes, 70, $oracle->ask('likely', null, new ScriptedRandomNumberGenerator(70)));
        $this->assertAnswer(YesNoAnswer::No, 70, $oracle->ask('likely', null, new ScriptedRandomNumberGenerator(97)));
        $this->assertAnswer(YesNoAnswer::ExceptionalNo, 70, $oracle->ask('likely', null, new ScriptedRandomNumberGenerator(98)));

        $this->expectException(InvalidLikelihoodOracle::class);
        $oracle->ask('likely', 5, new ScriptedRandomNumberGenerator(50));
    }

    #[Test]
    public function mythicStylePublishesOnceAndResolvesItsOracles(): void
    {
        $this->assertPublishesOnce('mythic-style');

        $snapshot = $this->snapshot('mythic-style');
        self::assertSame('Mythic-style', $snapshot->name());
        self::assertSame(1, $snapshot->releaseVersion());
        self::assertSame([], $snapshot->flowSteps());

        self::assertSame(['meaning-action', 'meaning-subject', 'random-event-focus'], $snapshot->oracleTableKeys());
        $this->assertWeightedTableResolves($snapshot, 'meaning-action', 30, 'Mend', 'Awaken');
        $this->assertWeightedTableResolves($snapshot, 'meaning-subject', 30, 'A debt', 'A key');
        $this->assertRangedTableResolves($snapshot, 'random-event-focus', 'Something changes far away', 'A quiet moment hides a clue');

        self::assertSame(['fate'], $this->likelihoodKeys($snapshot));
        $fate = $snapshot->likelihoodOracle('fate');
        self::assertSame('Fate question', $fate->name());
        $oracle = $fate->oracle();
        self::assertSame([1, 9, 5, 5], [$oracle->chaos()?->min(), $oracle->chaos()?->max(), $oracle->chaos()?->neutral(), $oracle->chaos()?->shiftPerPoint()]);
        self::assertSame([
            'impossible' => 5,
            'nearly-impossible' => 15,
            'very-unlikely' => 25,
            'unlikely' => 35,
            'even' => 50,
            'likely' => 65,
            'very-likely' => 75,
            'nearly-certain' => 85,
            'certain' => 95,
        ], $this->targets($oracle->levels()));

        // Without a chaos factor the neutral one applies: target 50, exceptional bands 1–10 and 91–100.
        $this->assertAnswer(YesNoAnswer::ExceptionalYes, 50, $oracle->ask('even', null, new ScriptedRandomNumberGenerator(10)));
        $this->assertAnswer(YesNoAnswer::Yes, 50, $oracle->ask('even', null, new ScriptedRandomNumberGenerator(50)));
        $this->assertAnswer(YesNoAnswer::No, 50, $oracle->ask('even', null, new ScriptedRandomNumberGenerator(90)));
        $this->assertAnswer(YesNoAnswer::ExceptionalNo, 50, $oracle->ask('even', null, new ScriptedRandomNumberGenerator(91)));

        // Each chaos point moves the target by 5, clamped to the die.
        $this->assertAnswer(YesNoAnswer::Yes, 70, $oracle->ask('even', 9, new ScriptedRandomNumberGenerator(70)));
        $this->assertAnswer(YesNoAnswer::No, 30, $oracle->ask('even', 1, new ScriptedRandomNumberGenerator(31)));
        $this->assertAnswer(YesNoAnswer::Yes, 100, $oracle->ask('certain', 9, new ScriptedRandomNumberGenerator(100)));
        $this->assertAnswer(YesNoAnswer::No, 0, $oracle->ask('impossible', 1, new ScriptedRandomNumberGenerator(1)));
    }

    private function assertPublishesOnce(string $preset): void
    {
        $file = self::PRESETS.'/'.$preset.'.json';

        self::assertSame(Command::SUCCESS, $this->tester->execute(['file' => $file, '--if-changed' => true]), $this->tester->getDisplay());
        self::assertStringContainsString(\sprintf('Published %s v1', $preset), $this->tester->getDisplay());

        self::assertSame(Command::SUCCESS, $this->tester->execute(['file' => $file, '--if-changed' => true]), $this->tester->getDisplay());
        self::assertStringContainsString(\sprintf('Unchanged %s (v1)', $preset), $this->tester->getDisplay());
    }

    private function snapshot(string $key): GameSystemSnapshot
    {
        return self::getContainer()->get(QueryBus::class)->ask(new GetGameSystemSnapshot($key));
    }

    /**
     * Rolls the lowest and the highest number of 1dW, W being the total weight (every weight is 1).
     */
    private function assertWeightedTableResolves(GameSystemSnapshot $snapshot, string $table, int $entries, string $first, string $last): void
    {
        self::assertSame($first, $snapshot->resolveOracleTable($table, new ScriptedRandomNumberGenerator(1))->steps()[0]->text());
        self::assertSame($last, $snapshot->resolveOracleTable($table, new ScriptedRandomNumberGenerator($entries))->steps()[0]->text());

        try {
            $snapshot->resolveOracleTable($table, new ScriptedRandomNumberGenerator($entries + 1));
            self::fail(\sprintf('%s has more than %d entries.', $table, $entries));
        } catch (\LogicException $logicException) {
            self::assertStringContainsString(\sprintf('outside [1, %d]', $entries), $logicException->getMessage());
        }
    }

    private function assertRangedTableResolves(GameSystemSnapshot $snapshot, string $table, string $atMin, string $atMax): void
    {
        $min = $snapshot->resolveOracleTable($table, new ScriptedRandomNumberGenerator(1))->steps()[0];
        self::assertSame('1d100', $min->dice());
        self::assertSame($atMin, $min->text());

        self::assertSame($atMax, $snapshot->resolveOracleTable($table, new ScriptedRandomNumberGenerator(100))->steps()[0]->text());

        foreach (range(1, 100) as $total) {
            self::assertSame($total, $snapshot->resolveOracleTable($table, new ScriptedRandomNumberGenerator($total))->steps()[0]->total(), 'Every roll of 1d100 has an entry.');
        }
    }

    private function assertAnswer(YesNoAnswer $expected, int $effectiveTarget, LikelihoodAnswer $answer): void
    {
        self::assertSame([$expected, $effectiveTarget], [$answer->answer(), $answer->effectiveTarget()]);
    }

    /**
     * @return list<string>
     */
    private function likelihoodKeys(GameSystemSnapshot $snapshot): array
    {
        return array_map(static fn (\App\Play\Domain\GameSystem\SnapshotLikelihoodOracle $oracle): string => $oracle->key(), $snapshot->likelihoodOracles());
    }

    /**
     * @param list<LikelihoodLevel> $levels
     *
     * @return array<string, int> targets by level key
     */
    private function targets(array $levels): array
    {
        $targets = [];
        foreach ($levels as $level) {
            $targets[$level->key()] = $level->target();
        }

        return $targets;
    }
}
