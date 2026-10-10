<?php

declare(strict_types=1);

namespace App\Tests\Support\Play;

use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Infrastructure\GameSystem\GameSystemReleaseTranslator;

/**
 * A release to be guided along, with a step of every kind a player completes.
 */
final class GuidedReleases
{
    /**
     * Release v1 of "guided": oracle tables "scene-kinds" (1d6, every entry names Scene Type "tour")
     * and "omens" (1d6: 1-3 Calm, 4-6 Storm), and the likelihood oracle "fate" (d100; "even" 50,
     * "unlikely" 35; chaos 1-9, neutral 5, 5 per point). Scene Type "tour" sets up with the steps
     * "intro" (prompt), "dice" (roll 1d6), "omen" (table), "ask" (oracle, the player picks the
     * likelihood), "ask-even" (oracle at "even") and "fork" (choice: "left" Go left, "right" Go
     * right); "solo" closes with a prompt "wrap"; "quiet" has no steps. Flows: "draw" (the scene
     * pick rolls on "scene-kinds"), "tour" (the player picks "tour" or "quiet", any number of
     * times) and "chain" (the sequence "solo" over and over). With $chaosFromTracker, "fate" takes
     * its chaos factor from the counter Tracker "chaos" (1-9, starting at 5) instead.
     */
    public static function tour(bool $chaosFromTracker = false): GameSystemSnapshot
    {
        return new GameSystemReleaseTranslator()->translate(ReleaseViews::of(self::content($chaosFromTracker)));
    }

    /**
     * The content of the release tour() translates, as a game manager publishes it.
     *
     * @return array<string, mixed>
     */
    public static function content(bool $chaosFromTracker = false): array
    {
        $step = static fn (string $key, string $kind, string $title, array $fields = []): array => ['key' => $key, 'kind' => $kind, 'title' => $title] + $fields;
        $sceneType = static fn (string $key, string $name, array $setup = [], array $closing = []): array => ['key' => $key, 'name' => $name, 'purpose' => 'A '.$key.' scene.', 'oracles' => [], 'setup' => $setup, 'play' => [], 'closing' => $closing];
        $flow = static fn (string $key, string $mode, array $selection): array => ['key' => $key, 'name' => ucfirst($key), 'defaultView' => 'journal', 'oracles' => ['scene-kinds', 'omens', 'fate'], 'trackers' => $chaosFromTracker ? ['chaos'] : [], 'phases' => [
            ['key' => $key, 'name' => ucfirst($key), 'mode' => $mode, 'selection' => $selection],
        ]];

        return [
            'schemaVersion' => 2,
            'gameSystem' => ['key' => 'guided', 'name' => 'Guided'],
            'oracles' => [
                'tables' => [
                    ['key' => 'scene-kinds', 'name' => 'Scene kinds', 'dice' => '1d6', 'entries' => [['min' => 1, 'max' => 6, 'text' => 'A tour', 'sceneType' => 'tour']]],
                    ['key' => 'omens', 'name' => 'Omens', 'dice' => '1d6', 'entries' => [['min' => 1, 'max' => 3, 'text' => 'Calm'], ['min' => 4, 'max' => 6, 'text' => 'Storm']]],
                ],
                'likelihood' => [['key' => 'fate', 'name' => 'Fate question', 'sides' => 100, 'levels' => [['key' => 'unlikely', 'label' => 'Unlikely', 'target' => 35], ['key' => 'even', 'label' => '50/50', 'target' => 50]], 'chaos' => ['min' => 1, 'max' => 9, 'neutral' => 5, 'shiftPerPoint' => 5] + ($chaosFromTracker ? ['tracker' => 'chaos'] : [])]],
            ],
            'trackers' => $chaosFromTracker ? [['key' => 'chaos', 'name' => 'Chaos factor', 'kind' => 'counter', 'min' => 1, 'max' => 9, 'initial' => 5]] : [],
            'factSlots' => [],
            'sceneTypes' => [
                $sceneType('tour', 'Tour', [
                    $step('intro', 'prompt', 'Who walks in?', ['prompt' => 'Describe the first person.']),
                    $step('dice', 'roll', 'Push your luck', ['dice' => '1d6']),
                    $step('omen', 'table', 'Read the omen', ['table' => 'omens']),
                    $step('ask', 'oracle', 'Is the gate open?', ['oracle' => 'fate']),
                    $step('ask-even', 'oracle', 'Is it guarded?', ['oracle' => 'fate', 'likelihood' => 'even']),
                    $step('fork', 'choice', 'Which way?', ['options' => [['key' => 'left', 'label' => 'Go left'], ['key' => 'right', 'label' => 'Go right']], 'skip' => 'left']),
                ]),
                $sceneType('solo', 'Solo', [], [$step('wrap', 'prompt', 'What changed?')]),
                $sceneType('quiet', 'Quiet'),
            ],
            'flows' => [
                $flow('draw', 'once', ['rule' => 'oracle', 'table' => 'scene-kinds']),
                $flow('tour', 'loop', ['rule' => 'player', 'sceneTypes' => ['tour', 'quiet']]),
                $flow('chain', 'loop', ['rule' => 'sequence', 'sceneTypes' => ['solo']]),
            ],
            'checks' => [],
        ];
    }
}
