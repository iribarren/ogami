<?php

declare(strict_types=1);

namespace App\Tests\Support\Play;

use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\SnapshotLikelihoodOracle;
use App\Randomness\Domain\Oracle\LikelihoodOracle;
use App\Randomness\Domain\Oracle\OracleTableSet;

/**
 * GameSystem snapshots for Play tests.
 */
final class Snapshots
{
    /**
     * A snapshot with no oracles.
     */
    public static function bare(string $key, string $name, int $version): GameSystemSnapshot
    {
        return new GameSystemSnapshot($key, $name, $version, null, [], []);
    }

    /**
     * A snapshot with two oracle tables ("weather", "storm-kind") and one likelihood oracle ("fate").
     */
    public static function withOracles(string $key, string $name, int $version): GameSystemSnapshot
    {
        $tables = OracleTableSet::fromArray([
            ['key' => 'weather', 'name' => 'Weather', 'dice' => '1d6', 'entries' => [
                ['min' => 1, 'max' => 4, 'text' => 'Clear'],
                ['min' => 5, 'max' => 6, 'text' => 'Storm', 'table' => 'storm-kind'],
            ]],
            ['key' => 'storm-kind', 'name' => 'Storm kind', 'entries' => [['text' => 'Rain'], ['text' => 'Hail']]],
        ]);
        $fate = LikelihoodOracle::fromArray([
            'sides' => 100,
            'levels' => [
                ['key' => 'unlikely', 'label' => 'Unlikely', 'target' => 35],
                ['key' => 'even', 'label' => '50/50', 'target' => 50],
            ],
            'chaos' => ['min' => 1, 'max' => 9, 'neutral' => 5, 'shiftPerPoint' => 5],
        ]);
        $plain = LikelihoodOracle::fromArray([
            'sides' => 6,
            'levels' => [['key' => 'even', 'label' => 'Even', 'target' => 3]],
        ]);

        return new GameSystemSnapshot($key, $name, $version, $tables, [
            new SnapshotLikelihoodOracle('fate', 'Fate question', $fate),
            new SnapshotLikelihoodOracle('plain', 'Plain question', $plain),
        ], []);
    }
}
