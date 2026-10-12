<?php

declare(strict_types=1);

namespace App\Tests\Support\Play;

/**
 * A release whose Scene Types work with NPCs and Threads: Tags "rival" and "ally", and the Flow
 * "tour" where the player picks the Scene Type. "spawn" and "spawn-thread" create an NPC or a
 * Thread from the answer to "ask" (then "show" reads {picked}); "choose" picks 1 to 2 rival NPCs
 * by id, "draw" draws one at random; "quiet" has no steps.
 */
final class PeopleReleases
{
    /**
     * @return array<string, mixed> the content of release v1 of "people", as a game manager publishes it
     */
    public static function content(): array
    {
        $step = static fn (string $key, string $kind, string $title, array $fields = []): array => ['key' => $key, 'kind' => $kind, 'title' => $title] + $fields;
        $sceneType = static fn (string $key, string $name, array $setup = []): array => ['key' => $key, 'name' => $name, 'purpose' => 'A '.$key.' scene.', 'oracles' => [], 'setup' => $setup, 'play' => [], 'closing' => []];

        return [
            'schemaVersion' => 2,
            'gameSystem' => ['key' => 'people', 'name' => 'People'],
            'oracles' => ['tables' => [], 'likelihood' => []],
            'trackers' => [],
            'factSlots' => [],
            'tags' => [['key' => 'rival', 'label' => 'Rival'], ['key' => 'ally', 'label' => 'Ally', 'hint' => 'Friendly.']],
            'sceneTypes' => [
                $sceneType('spawn', 'Spawn', [
                    $step('ask', 'prompt', 'Who turns up?', ['effects' => [['kind' => 'createNpc', 'name' => '{answer}', 'tags' => ['rival']]]]),
                    $step('show', 'prompt', 'Met {picked}'),
                ]),
                $sceneType('spawn-thread', 'Spawn a Thread', [
                    $step('ask', 'prompt', 'What is at stake?', ['effects' => [['kind' => 'createThread', 'name' => '{answer}', 'tags' => ['ally']]]]),
                    $step('show', 'prompt', 'Chasing {picked}'),
                ]),
                $sceneType('choose', 'Choose', [
                    $step('who', 'pick', 'Which rival?', ['of' => 'npc', 'tags' => ['rival'], 'mode' => 'choose', 'count' => ['min' => 1, 'max' => 2]]),
                    $step('show', 'prompt', 'With {picked}'),
                ]),
                $sceneType('draw', 'Draw', [
                    $step('who', 'pick', 'Who shows up?', ['of' => 'npc', 'mode' => 'random']),
                    $step('show', 'prompt', 'With {picked}'),
                ]),
                $sceneType('quiet', 'Quiet'),
            ],
            'flows' => [['key' => 'tour', 'name' => 'Tour', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => [], 'phases' => [
                ['key' => 'tour', 'name' => 'Tour', 'mode' => 'loop', 'selection' => ['rule' => 'player', 'sceneTypes' => ['spawn', 'spawn-thread', 'choose', 'draw', 'quiet']]],
            ]]],
            'checks' => [],
        ];
    }
}
