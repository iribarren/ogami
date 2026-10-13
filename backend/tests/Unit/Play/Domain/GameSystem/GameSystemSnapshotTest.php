<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\GameSystem;

use App\Play\Domain\GameSystem\Check\Check;
use App\Play\Domain\GameSystem\Check\CheckBand;
use App\Play\Domain\GameSystem\FlowStep;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\SnapshotLikelihoodOracle;
use App\Play\Domain\GameSystem\Tag;
use App\Randomness\Domain\DiceExpression;
use App\Randomness\Domain\Oracle\LikelihoodOracle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GameSystemSnapshot::class)]
#[CoversClass(InvalidGameSystemRelease::class)]
final class GameSystemSnapshotTest extends TestCase
{
    #[Test]
    public function likelihoodOracleKeysAreUnique(): void
    {
        $oracle = LikelihoodOracle::fromArray(['sides' => 6, 'levels' => [['key' => 'even', 'label' => 'Even', 'target' => 3]]]);

        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains('GameSystem "free-journal" v2 cannot be read by Play: oracles.likelihood[1].key: duplicate key "coin".');

        new GameSystemSnapshot('free-journal', 'Free journal', 2, null, [
            new SnapshotLikelihoodOracle('coin', 'Coin', $oracle),
            new SnapshotLikelihoodOracle('coin', 'Coin again', $oracle),
        ], []);
    }

    #[Test]
    public function flowStepKeysAreUnique(): void
    {
        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains('GameSystem "free-journal" v2 cannot be read by Play: flow.steps[1].key: duplicate key "act".');

        new GameSystemSnapshot('free-journal', 'Free journal', 2, null, [], [
            new FlowStep('act', 'Act', null),
            new FlowStep('act', 'Act again', null),
        ]);
    }

    #[Test]
    public function tagKeysAreUnique(): void
    {
        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains('GameSystem "free-journal" v2 cannot be read by Play: tags[1].key: duplicate key "ally".');

        new GameSystemSnapshot('free-journal', 'Free journal', 2, null, [], [], tags: [
            new Tag('ally', 'Ally'),
            new Tag('ally', 'Ally again'),
        ]);
    }

    #[Test]
    public function checksAreListedInDefinitionOrderAndFoundByKey(): void
    {
        $luck = $this->check('luck');
        $skill = $this->check('skill');

        $snapshot = new GameSystemSnapshot('free-journal', 'Free journal', 2, null, [], [], checks: [$skill, $luck]);

        self::assertSame([$skill, $luck], $snapshot->checks());
        self::assertSame($luck, $snapshot->check('luck'));
        self::assertNull($snapshot->check('ghost'));
        self::assertSame([], new GameSystemSnapshot('free-journal', 'Free journal', 2, null, [], [])->checks());
    }

    #[Test]
    public function checkKeysAreUnique(): void
    {
        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains('GameSystem "free-journal" v2 cannot be read by Play: checks[1].key: duplicate key "luck".');

        new GameSystemSnapshot('free-journal', 'Free journal', 2, null, [], [], checks: [$this->check('luck'), $this->check('luck')]);
    }

    private function check(string $key): Check
    {
        return new Check($key, ucfirst($key), null, DiceExpression::fromString('1d6'), null, null, [new CheckBand('only', 'Only')]);
    }
}
