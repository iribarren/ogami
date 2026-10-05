<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\GameSystem;

use App\Play\Domain\GameSystem\FlowStep;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\SnapshotLikelihoodOracle;
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
}
