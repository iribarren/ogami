<?php

declare(strict_types=1);

namespace App\Tests\Support\Play;

use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Infrastructure\GameSystem\GameSystemReleaseTranslator;

/**
 * A release "notes" whose Flows decide things on their own, for the tests of the Flow outcomes:
 * counters "heat" (0-5) and "zero" (0-9), and in the loop phase "Roaming" the Scene Types
 * "gamble" (a roll with labelled bands, "Jackpot" adds 2 Heat), "watch" (a condition on Heat:
 * "Calm" up to 1, a next Scene Type up to 3, "Hot" ends the phase), "heist" (a prompt that
 * switches to "escape" and adds 1 Heat), "escape" and "broken" (a prompt whose second Effect
 * divides by zero). Flow "notes" plays them; flow "closing" also has a session closing whose
 * condition step takes the band "Quiet night".
 */
final class FlowNotesReleases
{
    private function __construct()
    {
    }

    public static function snapshot(): GameSystemSnapshot
    {
        return new GameSystemReleaseTranslator()->translate(ReleaseViews::of(self::content()));
    }

    /**
     * @return array<string, mixed>
     */
    public static function content(): array
    {
        $prompt = static fn (string $key, string $title, array $fields = []): array => ['key' => $key, 'kind' => 'prompt', 'title' => $title] + $fields;
        $sceneType = static fn (string $key, array $setup): array => ['key' => $key, 'name' => ucfirst($key), 'purpose' => 'A '.$key.' scene.', 'oracles' => [], 'setup' => $setup, 'play' => [], 'closing' => []];
        $tracker = static fn (string $key, string $op, int|array $value): array => ['kind' => 'tracker', 'tracker' => $key, 'op' => $op, 'value' => $value];
        $sceneTypes = ['gamble', 'watch', 'heist', 'escape', 'broken'];

        return [
            'schemaVersion' => 2,
            'gameSystem' => ['key' => 'notes', 'name' => 'Notes'],
            'oracles' => ['tables' => [], 'likelihood' => []],
            'trackers' => [
                ['key' => 'heat', 'name' => 'Heat', 'kind' => 'counter', 'min' => 0, 'max' => 5, 'initial' => 0],
                ['key' => 'zero', 'name' => 'Zero', 'kind' => 'counter', 'min' => 0, 'max' => 9, 'initial' => 0],
            ],
            'factSlots' => [],
            'sceneTypes' => [
                $sceneType('gamble', [
                    ['key' => 'gamble', 'kind' => 'roll', 'title' => 'Push your luck', 'dice' => '1d6', 'bands' => [
                        ['upTo' => 1, 'label' => 'Bust'],
                        ['upTo' => 5],
                        ['label' => 'Jackpot', 'effects' => [$tracker('heat', 'add', 2)]],
                    ]],
                ]),
                $sceneType('watch', [
                    ['key' => 'check', 'kind' => 'condition', 'title' => 'How hot is it?', 'tracker' => 'heat', 'bands' => [
                        ['upTo' => 1, 'label' => 'Calm'],
                        ['upTo' => 3, 'effects' => [['kind' => 'nextScene', 'sceneType' => 'gamble']]],
                        ['label' => 'Hot', 'effects' => [['kind' => 'endPhase']]],
                    ]],
                ]),
                $sceneType('heist', [$prompt('plan', 'What is the plan?', ['effects' => [['kind' => 'switchSceneType', 'sceneType' => 'escape'], $tracker('heat', 'add', 1)]])]),
                $sceneType('escape', [$prompt('run', 'Run!')]),
                $sceneType('broken', [$prompt('p', 'Go', ['effects' => [$tracker('heat', 'add', 1), $tracker('heat', 'add', ['formula' => '1 / {tracker:zero}'])]])]),
            ],
            'flows' => [['key' => 'notes', 'name' => 'Notes', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => ['heat', 'zero'], 'phases' => [
                ['key' => 'roam', 'name' => 'Roaming', 'mode' => 'loop', 'selection' => ['rule' => 'player', 'sceneTypes' => $sceneTypes]],
            ]], ['key' => 'closing', 'name' => 'Closing', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => ['heat', 'zero'], 'phases' => [
                ['key' => 'roam', 'name' => 'Roaming', 'mode' => 'loop', 'selection' => ['rule' => 'player', 'sceneTypes' => $sceneTypes], 'sessionClosing' => [
                    ['key' => 'wrap', 'kind' => 'condition', 'title' => 'How did it end?', 'tracker' => 'heat', 'bands' => [['label' => 'Quiet night']]],
                ]],
            ]]],
            'checks' => [],
        ];
    }
}
