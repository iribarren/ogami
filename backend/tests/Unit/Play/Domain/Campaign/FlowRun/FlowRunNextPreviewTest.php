<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The "Next:" preview of a step that decides where the Flow goes on: it names the next step only
 * when every outcome leads to it, else it says what decides (the roll, or the player's answer).
 * The scene type holds the branching step "x" and the prompts "a", "b" and "c" after it.
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(Campaign::class)]
final class FlowRunNextPreviewTest extends FlowRunTestCase
{
    private const array TITLE = ['effects' => [['kind' => 'sceneTitle', 'title' => 'Seen']]];

    /**
     * @param array<string, mixed> $x the branching step, without its key, kind and title
     */
    #[Test]
    #[DataProvider('previews')]
    public function aStepNamesTheNextStepOnlyWhenEveryOutcomeLeadsToIt(string $kind, array $x, string $next): void
    {
        $release = self::translate($this->content(['key' => 'x', 'kind' => $kind, 'title' => 'The branch'] + $x));
        $campaign = self::guided($release, 'preview');
        $campaign->startSession(self::at(), $release);
        $campaign->pickSceneType('case', $release, self::at());

        $view = $campaign->flowRunView($release);
        self::assertSame('x', $view?->step?->step->key);
        self::assertSame($next, $view->next);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function previews(): iterable
    {
        $roll = ['dice' => '1d6'];
        $table = ['table' => 'loot'];
        $oracle = ['oracle' => 'fate', 'likelihood' => 'even'];
        $pick = ['of' => 'npc', 'mode' => 'choose', 'mandatory' => false];

        yield 'a roll whose bands go different ways' => ['roll', $roll + ['bands' => [['upTo' => 3, 'next' => 'b'], []]], 'decided by the roll'];
        yield 'a roll whose bands all go to the same step' => ['roll', $roll + ['bands' => [['upTo' => 3, 'next' => 'b'], ['next' => 'b']]], 'Setup: Step b'];
        yield 'a roll whose bands only differ by their effects' => ['roll', $roll + ['bands' => [['upTo' => 3] + self::TITLE, []]], 'Setup: Step a'];
        yield 'a roll with a band that ends the part' => ['roll', $roll + ['bands' => [['upTo' => 3, 'next' => 'end'], []]], 'decided by the roll'];
        yield 'a roll without bands' => ['roll', $roll, 'Setup: Step a'];
        yield 'a table with a branch and an otherwise that differ' => ['table', $table + ['branches' => [['entry' => 'gold', 'next' => 'b']], 'otherwise' => ['next' => 'c']], 'decided by the roll'];
        yield 'a table whose branch and otherwise agree' => ['table', $table + ['branches' => [['entry' => 'gold', 'next' => 'b']], 'otherwise' => ['next' => 'b']], 'Setup: Step b'];
        yield 'a table with a branch and no otherwise' => ['table', $table + ['branches' => [['entry' => 'gold', 'next' => 'b']]], 'decided by the roll'];
        yield 'a table without branches' => ['table', $table, 'Setup: Step a'];
        yield 'an oracle whose answers differ' => ['oracle', $oracle + ['branches' => ['yes' => ['next' => 'b'], 'no' => ['next' => 'c']]], 'decided by your answer'];
        yield 'an oracle with one branch' => ['oracle', $oracle + ['branches' => ['yes' => ['next' => 'b']]], 'decided by your answer'];
        yield 'an oracle whose exceptional answer ends the part' => ['oracle', $oracle + ['branches' => ['yes' => ['next' => 'b'], 'no' => ['next' => 'b'], 'exceptionalNo' => ['next' => 'end']]], 'decided by your answer'];
        yield 'an oracle whose answers all go to the same step' => ['oracle', $oracle + ['branches' => ['yes' => ['next' => 'b'], 'no' => ['next' => 'b']]], 'Setup: Step b'];
        yield 'a choice whose options differ' => ['choice', ['options' => [['key' => 'left', 'label' => 'Left', 'next' => 'b'], ['key' => 'right', 'label' => 'Right']]], 'decided by your answer'];
        yield 'a choice whose options agree' => ['choice', ['options' => [['key' => 'left', 'label' => 'Left', 'next' => 'b'], ['key' => 'right', 'label' => 'Right', 'next' => 'b']]], 'Setup: Step b'];
        yield 'a pick whose found and none differ' => ['pick', $pick + ['branches' => ['found' => ['next' => 'b'], 'none' => ['next' => 'c']]], 'decided by your answer'];
        yield 'a pick with only a none branch' => ['pick', $pick + ['branches' => ['none' => ['next' => 'b']]], 'decided by your answer'];
        yield 'a pick without branches' => ['pick', $pick, 'Setup: Step a'];
        yield 'a prompt that goes on to another step' => ['prompt', ['next' => 'c'], 'Setup: Step c'];
    }

    #[Test]
    public function aRollWhoseBandsAllEndThePartNamesWhatFollowsThePart(): void
    {
        $release = self::translate($this->content(['key' => 'x', 'kind' => 'roll', 'title' => 'The branch', 'dice' => '1d6', 'bands' => [['upTo' => 3, 'next' => 'end'], ['next' => 'end']]]));
        $campaign = self::guided($release, 'preview');
        $campaign->startSession(self::at(), $release);
        $campaign->pickSceneType('case', $release, self::at());

        self::assertSame('Open play', $campaign->flowRunView($release)?->next);
    }

    #[Test]
    public function aHookStepThatDecidesBranchesLikeASceneStep(): void
    {
        $release = self::translate($this->content(['key' => 'x', 'kind' => 'prompt', 'title' => 'Unused'], [
            ['key' => 'x', 'kind' => 'roll', 'title' => 'The omen', 'dice' => '1d6', 'bands' => [['upTo' => 3, 'next' => 'b'], []]],
            ['key' => 'a', 'kind' => 'prompt', 'title' => 'Step a'],
            ['key' => 'b', 'kind' => 'prompt', 'title' => 'Step b'],
        ]));
        $campaign = self::guided($release, 'preview');
        $campaign->startSession(self::at(), $release);

        $view = $campaign->flowRunView($release);
        self::assertSame('x', $view?->step?->step->key);
        self::assertSame('decided by the roll', $view->next);
    }

    /**
     * @param array<string, mixed>        $x              the branching step of the scene type "case"
     * @param ?list<array<string, mixed>> $sessionOpening
     *
     * @return array<string, mixed>
     */
    private function content(array $x, ?array $sessionOpening = null): array
    {
        $prompt = static fn (string $key): array => ['key' => $key, 'kind' => 'prompt', 'title' => 'Step '.$key];
        $sceneType = static fn (string $key, array $setup): array => ['key' => $key, 'name' => ucfirst($key), 'purpose' => 'A '.$key.' scene.', 'oracles' => [], 'setup' => $setup, 'play' => [], 'closing' => []];

        return [
            'schemaVersion' => 2,
            'gameSystem' => ['key' => 'preview', 'name' => 'Preview'],
            'oracles' => [
                'tables' => [['key' => 'loot', 'name' => 'Loot', 'dice' => '1d6', 'entries' => [
                    ['min' => 1, 'max' => 3, 'key' => 'gold', 'text' => 'Gold'],
                    ['min' => 4, 'max' => 6, 'text' => 'Dust'],
                ]]],
                'likelihood' => [['key' => 'fate', 'name' => 'Fate question', 'sides' => 100, 'levels' => [['key' => 'even', 'label' => '50/50', 'target' => 50]], 'chaos' => ['min' => 1, 'max' => 9, 'neutral' => 5, 'shiftPerPoint' => 5]]],
            ],
            'trackers' => [],
            'factSlots' => [],
            'sceneTypes' => [$sceneType('case', [$x, $prompt('a'), $prompt('b'), $prompt('c')]), $sceneType('other', [$prompt('a')])],
            'flows' => [['key' => 'preview', 'name' => 'Preview', 'defaultView' => 'journal', 'oracles' => ['loot', 'fate'], 'trackers' => [], 'phases' => [
                ['key' => 'only', 'name' => 'Only', 'mode' => 'loop', 'selection' => ['rule' => 'player', 'sceneTypes' => ['case', 'other']]] + (null === $sessionOpening ? [] : ['sessionOpening' => $sessionOpening]),
            ]]],
            'checks' => [],
        ];
    }
}
