<?php

declare(strict_types=1);

namespace App\Tests\Support\Play;

use App\Play\Domain\GameSystem\Flow\StepList;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\SceneType;
use App\Play\Domain\GameSystem\SnapshotLikelihoodOracle;
use App\Play\Domain\GameSystem\Tracker;
use App\Play\Domain\GameSystem\TrackerLevel;
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

    /**
     * A schema version 2 snapshot with trackers: "alarm" (clock of 6), "heat" (counter -5..5 from
     * -5, levels Cold up to -1, Warm up to 2, Hot) and "chaos" (counter 1..9 from 5). Likelihood
     * oracle "fate" takes its chaos factor from "chaos"; "omen" takes one from the request.
     */
    public static function withTrackers(string $key, string $name, int $version): GameSystemSnapshot
    {
        $chaos = static fn (int $min, int $max, int $neutral): LikelihoodOracle => LikelihoodOracle::fromArray([
            'sides' => 100,
            'levels' => [['key' => 'even', 'label' => '50/50', 'target' => 50]],
            'chaos' => ['min' => $min, 'max' => $max, 'neutral' => $neutral, 'shiftPerPoint' => 5],
        ]);

        return new GameSystemSnapshot($key, $name, $version, null, [
            new SnapshotLikelihoodOracle('fate', 'Fate question', $chaos(1, 9, 5), 'chaos'),
            new SnapshotLikelihoodOracle('omen', 'Omen', $chaos(0, 2, 1)),
        ], [], [
            Tracker::clock('alarm', 'Alarm', 'At 6/6 security locks down', 6),
            Tracker::counter('heat', 'Heat', null, -5, 5, -5, [new TrackerLevel(-1, 'Cold'), new TrackerLevel(2, 'Warm'), new TrackerLevel(null, 'Hot')]),
            Tracker::counter('chaos', 'Chaos factor', null, 1, 9, 5),
        ]);
    }

    /**
     * A schema version 2 snapshot with the Scene Types "legwork" (Legwork) and "firefight"
     * (Firefight), without steps.
     */
    public static function withSceneTypes(string $key, string $name, int $version): GameSystemSnapshot
    {
        $sceneType = static fn (string $key, string $name): SceneType => new SceneType($key, $name, 'Play out a '.$key.' scene.', null, [], new StepList(), new StepList(), new StepList());

        return new GameSystemSnapshot($key, $name, $version, null, [], [], sceneTypes: [$sceneType('legwork', 'Legwork'), $sceneType('firefight', 'Firefight')]);
    }
}
