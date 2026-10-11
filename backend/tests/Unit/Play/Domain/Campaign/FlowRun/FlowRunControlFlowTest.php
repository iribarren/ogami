<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunEvent;
use App\Play\Domain\Campaign\FlowRun\FlowRunHistoryEntry;
use App\Play\Domain\Campaign\FlowRun\FlowStepView;
use App\Play\Domain\Campaign\FlowRun\Placeholders;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Randomness\Domain\Oracle\OracleTableResult;
use App\Randomness\Domain\Oracle\OracleTableStep;
use App\Randomness\Domain\Oracle\YesNoAnswer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Outcomes, Effects and placeholders while a FlowRun plays, on a test release with Trackers "heat"
 * (counter 0-5, starting at 0), "limit" (counter 0-9, starting at 4) and "alarm" (clock, 4
 * segments), and one loop phase where the player picks any Scene Type: "ask" (oracle branches),
 * "search" (table branches, entry Effects of nested tables), "gamble" (roll bands), "crossroads"
 * (choice options, skip), "watch" (condition bands), "heist" / "escape" / "again" (Scene Type
 * switches) and "titles" (scene titles).
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(Campaign::class)]
#[CoversClass(FlowStepView::class)]
#[CoversClass(Placeholders::class)]
final class FlowRunControlFlowTest extends FlowRunTestCase
{
    private GameSystemSnapshot $release;

    protected function setUp(): void
    {
        $this->release = self::translate($this->content());
    }

    /**
     * @return iterable<string, array{YesNoAnswer, array{?string, ?string}}>
     */
    public static function oracleAnswers(): iterable
    {
        yield 'yes' => [YesNoAnswer::Yes, ['setup', 'won']];
        yield 'an exceptional yes without its branch follows yes' => [YesNoAnswer::ExceptionalYes, ['setup', 'won']];
        yield 'no' => [YesNoAnswer::No, ['setup', 'lost']];
        yield 'an exceptional no with its own branch' => [YesNoAnswer::ExceptionalNo, ['open', null]];
    }

    /**
     * @param array{?string, ?string} $position
     */
    #[Test]
    #[DataProvider('oracleAnswers')]
    public function anOracleStepBranchesByItsAnswerAfterItsOwnEffects(YesNoAnswer $answer, array $position): void
    {
        $campaign = $this->inScene('ask');

        $campaign->completeFlowStep('ask', self::oracle($answer), $this->release, self::at());

        self::assertSame([$position, 1], [self::position($campaign), $this->tracker($campaign, 'heat')]);
    }

    /**
     * @return iterable<string, array{OracleTableResult, string, int, int}>
     */
    public static function tableRolls(): iterable
    {
        // Step "heat set 1", then the rolled entries' Effects, then the outcome's.
        yield 'an entry with a branch' => [self::rolls(['loot', 1, 'Gold']), 'rich', 3, 0];
        yield 'nested entries, then "otherwise"' => [self::rolls(['loot', 3, 'Trap', 'traps'], ['traps', 2, 'Spikes']), 'poor', 0, 3];
        yield 'an entry without a key takes "otherwise"' => [self::rolls(['loot', 6, 'Dust']), 'poor', 0, 0];
    }

    #[Test]
    #[DataProvider('tableRolls')]
    public function aTableStepBranchesByTheRolledEntryAndAppliesEffectsInOrder(OracleTableResult $result, string $next, int $heat, int $alarm): void
    {
        $campaign = $this->inScene('search');

        $campaign->completeFlowStep('search', StepResult::table($result), $this->release, self::at());

        self::assertSame([['setup', $next], $heat, $alarm], [self::position($campaign), $this->tracker($campaign, 'heat'), $this->tracker($campaign, 'alarm')]);
        self::assertSame([], self::history($campaign), 'Effects are no hand edits.');
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function rollTotals(): iterable
    {
        yield 'under the first band' => [1, 4, 'bust'];
        yield 'on a literal upTo' => [2, 4, 'bust'];
        yield 'under a Tracker upTo' => [3, 4, 'fair'];
        yield 'on a Tracker upTo' => [4, 4, 'fair'];
        yield 'past every upTo' => [5, 4, 'jackpot'];
        yield 'on a Tracker upTo raised by hand' => [5, 5, 'fair'];
    }

    #[Test]
    #[DataProvider('rollTotals')]
    public function aRollStepBranchesByTheBandOfItsTotal(int $total, int $limit, string $next): void
    {
        $campaign = $this->inScene('gamble');
        $campaign->setTrackerValue($this->trackerOf($this->release, 'limit'), $limit);

        $campaign->completeFlowStep('gamble', self::roll('1d6', $total), $this->release, self::at());

        self::assertSame(['setup', $next], self::position($campaign));
        self::assertSame((string) $total, self::flowRunOf($campaign)->answers()['gamble']);
    }

    #[Test]
    public function aBandEffectIsClampedToTheTrackerRange(): void
    {
        $campaign = $this->inScene('gamble');

        $campaign->completeFlowStep('gamble', self::roll('1d6', 6), $this->release, self::at());

        self::assertSame(5, $this->tracker($campaign, 'heat'));
    }

    #[Test]
    public function aChoiceFollowsItsOptionWithTheStepEffectsThenTheOptionEffects(): void
    {
        $campaign = $this->inScene('crossroads');

        $campaign->completeFlowStep('way', StepResult::choice('right'), $this->release, self::at());

        // "right" has no next: the following step runs; its title rendered the answer and the Tracker.
        self::assertSame([['setup', 'middle'], 1, 'Went Right at 0'], [self::position($campaign), $this->tracker($campaign, 'alarm'), $campaign->currentScene()?->title()]);
    }

    #[Test]
    public function aSkippedChoiceFollowsItsSkipOptionWithOnlyThatOptionsEffects(): void
    {
        $campaign = $this->inScene('crossroads');

        $campaign->skipFlowStep('way', $this->release, self::at());

        self::assertSame([['setup', 'west'], 1, 0, []], [self::position($campaign), $this->tracker($campaign, 'heat'), $this->tracker($campaign, 'alarm'), self::flowRunOf($campaign)->answers()]);
        $step = $campaign->flowRunView($this->release)?->step;
        self::assertSame(['West at 1', 'You chose —', 'Mind the {Gap}', 'West at {tracker:heat}'], [$step?->title, $step?->prompt, $step?->tip, $step?->step->title]);
    }

    #[Test]
    public function anotherSkippedStepGoesToItsNextWithoutEffects(): void
    {
        $campaign = $this->inScene('crossroads');
        $campaign->completeFlowStep('way', StepResult::choice('right'), $this->release, self::at());

        $campaign->skipFlowStep('middle', $this->release, self::at());

        self::assertSame([['open', null], 0], [self::position($campaign), $this->tracker($campaign, 'heat')]);
    }

    /**
     * @return iterable<string, array{int, array{?string, ?string}, ?string, ?string}>
     */
    public static function heatLevels(): iterable
    {
        yield 'the first band' => [1, ['setup', 'calm'], null, null];
        yield 'a band up to a Tracker, without next' => [4, ['setup', 'warm'], 'ask', null];
        yield 'the band catching the rest' => [5, ['setup', 'hot'], null, 'endPhase'];
    }

    /**
     * @param array{?string, ?string} $position
     */
    #[Test]
    #[DataProvider('heatLevels')]
    public function aConditionStepBranchesByTheTrackerOnItsOwn(int $heat, array $position, ?string $forced, ?string $phaseEnding): void
    {
        $campaign = $this->inASession();
        $campaign->setTrackerValue($this->trackerOf($this->release, 'heat'), $heat);

        $campaign->pickSceneType('watch', $this->release, self::at());

        $flowRun = self::flowRunOf($campaign);
        self::assertSame([$position, $forced, $phaseEnding, 1], [self::position($campaign), $flowRun->forcedNextSceneType(), $flowRun->phaseEndingReason(), $this->tracker($campaign, 'alarm')]);
        self::assertArrayNotHasKey('check', $flowRun->answers());
    }

    #[Test]
    public function aSwitchThroughEffectsChangesTheSceneTypeInPlaceAndRestartsAtItsSetup(): void
    {
        $campaign = $this->inScene('heist');

        $campaign->completeFlowStep('plan', self::prompt('In through the roof'), $this->release, self::at());

        // The rest of the Effects still apply; the step's next ("later") is not followed.
        $flowRun = self::flowRunOf($campaign);
        self::assertSame([['setup', 'run'], 'escape', 'escape', 'Heist 1', 1, 1], [self::position($campaign), $flowRun->sceneType(), $campaign->currentScene()?->sceneType(), $campaign->currentScene()?->title(), $flowRun->switchCount(), $this->tracker($campaign, 'heat')]);
        self::assertEquals([FlowRunHistoryEntry::sceneTypeSwitch(self::at(), 1, 'heist', 'escape')], $flowRun->history());
    }

    #[Test]
    public function aSecondSwitchThroughEffectsInTheSceneIsIgnoredAndRecorded(): void
    {
        $campaign = $this->inScene('heist');
        $campaign->completeFlowStep('plan', self::prompt('In through the roof'), $this->release, self::at());

        $campaign->completeFlowStep('run', self::prompt('Over the rooftops'), $this->release, self::at());

        $flowRun = self::flowRunOf($campaign);
        self::assertSame([['open', null], 'escape', 1, 'Run'], [self::position($campaign), $flowRun->sceneType(), $flowRun->switchCount(), $campaign->currentScene()?->title()]);
        self::assertEquals(FlowRunHistoryEntry::sceneTypeSwitchIgnored(self::at(), 1, 'heist'), array_last($flowRun->history()));
        self::assertSame(FlowRunEvent::SceneTypeSwitchIgnored, array_last($flowRun->history())?->event);
    }

    #[Test]
    public function aSwitchToTheSameTypeRestartsItsSetupAndHandSwitchesDoNotCount(): void
    {
        $campaign = $this->inScene('again');
        $campaign->switchSceneType($this->sceneTypeOf($this->release, 'again'), $this->release, self::at());

        $campaign->completeFlowStep('again', self::prompt('Once'), $this->release, self::at());

        self::assertSame([['setup', 'again'], 1], [self::position($campaign), self::flowRunOf($campaign)->switchCount()]);
        $campaign->completeFlowStep('again', self::prompt('Twice'), $this->release, self::at());
        self::assertSame(['open', null], self::position($campaign));
        self::assertSame(['sceneTypeSwitch', 'sceneTypeSwitch', 'sceneTypeSwitchIgnored'], array_map(static fn (FlowRunHistoryEntry $entry): string => $entry->event->value, self::flowRunOf($campaign)->history()));
    }

    #[Test]
    public function aSceneTitleIsRenderedCutToTheLongestTitleAndIgnoredWhenEmpty(): void
    {
        $campaign = $this->inScene('titles');

        $campaign->completeFlowStep('name', self::prompt(str_repeat('a', 60)), $this->release, self::at());
        self::assertSame(str_repeat('a', 100), $campaign->currentScene()?->title());

        $campaign->completeFlowStep('blank', self::table('loot', '', 6), $this->release, self::at());
        self::assertSame(str_repeat('a', 100), $campaign->currentScene()?->title());
    }

    #[Test]
    public function aFailedCommandLeavesTheEffectsUndone(): void
    {
        $campaign = $this->inScene('crossroads');
        $before = clone $campaign;

        try {
            $campaign->completeFlowStep('way', StepResult::choice('up'), $this->release, self::at());
            self::fail('An unknown option was accepted.');
        } catch (\DomainException) {
            self::assertEquals($before, $campaign);
        }
    }

    private function inASession(): Campaign
    {
        $campaign = self::guided($this->release, 'control');
        $campaign->startSession(self::at(), $this->release);

        return $campaign;
    }

    private function inScene(string $sceneType): Campaign
    {
        $campaign = $this->inASession();
        $campaign->pickSceneType($sceneType, $this->release, self::at());

        return $campaign;
    }

    private function tracker(Campaign $campaign, string $key): int
    {
        return $campaign->trackerValue($this->trackerOf($this->release, $key));
    }

    private function trackerOf(GameSystemSnapshot $release, string $key): \App\Play\Domain\GameSystem\Tracker
    {
        return $release->tracker($key) ?? self::fail('No Tracker '.$key);
    }

    private function sceneTypeOf(GameSystemSnapshot $release, string $key): \App\Play\Domain\GameSystem\SceneType
    {
        return $release->sceneType($key) ?? self::fail('No Scene Type '.$key);
    }

    /**
     * @param array{0: string, 1: int, 2: string, 3?: string} $rolled    the table, total, text and nested table rolled first
     * @param array{0: string, 1: int, 2: string, 3?: string} ...$nested the nested tables rolled after it, alike
     */
    private static function rolls(array $rolled, array ...$nested): OracleTableResult
    {
        return new OracleTableResult([self::tableStep($rolled), ...array_values(array_map(self::tableStep(...), $nested))]);
    }

    /**
     * @param array{0: string, 1: int, 2: string, 3?: string} $step the table, total, text and nested table
     */
    private static function tableStep(array $step): OracleTableStep
    {
        return new OracleTableStep($step[0], ucfirst($step[0]), '1d6', $step[1], $step[2], $step[3] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function content(): array
    {
        $prompt = static fn (string $key, string $title, array $fields = []): array => ['key' => $key, 'kind' => 'prompt', 'title' => $title] + $fields;
        $sceneType = static fn (string $key, array $setup): array => ['key' => $key, 'name' => ucfirst($key), 'purpose' => 'A '.$key.' scene.', 'oracles' => [], 'setup' => $setup, 'play' => [], 'closing' => []];
        $tracker = static fn (string $key, string $op, int|array $value): array => ['kind' => 'tracker', 'tracker' => $key, 'op' => $op, 'value' => $value];
        $title = static fn (string $title): array => ['kind' => 'sceneTitle', 'title' => $title];
        $switch = static fn (string $sceneType): array => ['kind' => 'switchSceneType', 'sceneType' => $sceneType];
        $sceneTypes = ['ask', 'search', 'gamble', 'crossroads', 'watch', 'heist', 'escape', 'again', 'titles'];

        return [
            'schemaVersion' => 2,
            'gameSystem' => ['key' => 'control-flow', 'name' => 'Control flow'],
            'oracles' => [
                'tables' => [
                    ['key' => 'loot', 'name' => 'Loot', 'dice' => '1d6', 'entries' => [
                        ['min' => 1, 'max' => 2, 'key' => 'gold', 'text' => 'Gold', 'effects' => [$tracker('heat', 'add', 2)]],
                        ['min' => 3, 'max' => 4, 'key' => 'trap', 'text' => 'Trap', 'table' => 'traps', 'effects' => [$tracker('heat', 'add', 2)]],
                        ['min' => 5, 'max' => 6, 'text' => 'Dust'],
                    ]],
                    ['key' => 'traps', 'name' => 'Traps', 'dice' => '1d6', 'entries' => [
                        ['min' => 1, 'max' => 6, 'key' => 'spikes', 'text' => 'Spikes', 'effects' => [$tracker('alarm', 'set', ['tracker' => 'heat'])]],
                    ]],
                ],
                'likelihood' => [['key' => 'fate', 'name' => 'Fate question', 'sides' => 100, 'levels' => [['key' => 'even', 'label' => '50/50', 'target' => 50]], 'chaos' => ['min' => 1, 'max' => 9, 'neutral' => 5, 'shiftPerPoint' => 5]]],
            ],
            'trackers' => [
                ['key' => 'heat', 'name' => 'Heat', 'kind' => 'counter', 'min' => 0, 'max' => 5, 'initial' => 0],
                ['key' => 'limit', 'name' => 'Limit', 'kind' => 'counter', 'min' => 0, 'max' => 9, 'initial' => 4],
                ['key' => 'alarm', 'name' => 'Alarm', 'kind' => 'clock', 'segments' => 4],
            ],
            'factSlots' => [],
            'sceneTypes' => [
                $sceneType('ask', [
                    ['key' => 'ask', 'kind' => 'oracle', 'title' => 'Is the gate open?', 'oracle' => 'fate', 'likelihood' => 'even', 'effects' => [$tracker('heat', 'add', 1)], 'branches' => ['yes' => ['next' => 'won'], 'no' => ['next' => 'lost'], 'exceptionalNo' => ['next' => 'end']]],
                    $prompt('filler', 'Never on a branch'), $prompt('won', 'You won'), $prompt('lost', 'You lost'),
                ]),
                $sceneType('search', [
                    ['key' => 'search', 'kind' => 'table', 'title' => 'What is there?', 'table' => 'loot', 'effects' => [$tracker('heat', 'set', 1)], 'branches' => [['entry' => 'gold', 'next' => 'rich']], 'otherwise' => ['next' => 'poor', 'effects' => [$tracker('heat', 'set', 0)]]],
                    $prompt('rich', 'Rich'), $prompt('poor', 'Poor'),
                ]),
                $sceneType('gamble', [
                    ['key' => 'gamble', 'kind' => 'roll', 'title' => 'Push your luck', 'dice' => '1d6', 'bands' => [['upTo' => 2, 'next' => 'bust'], ['upTo' => ['tracker' => 'limit'], 'next' => 'fair'], ['next' => 'jackpot', 'effects' => [$tracker('heat', 'add', 9)]]]],
                    $prompt('bust', 'Bust'), $prompt('fair', 'Fair'), $prompt('jackpot', 'Jackpot'),
                ]),
                $sceneType('crossroads', [
                    ['key' => 'way', 'kind' => 'choice', 'title' => 'Which way?', 'effects' => [$tracker('alarm', 'add', 1)], 'options' => [
                        ['key' => 'left', 'label' => 'Left', 'next' => 'west', 'effects' => [$tracker('heat', 'add', 1)]],
                        ['key' => 'right', 'label' => 'Right', 'effects' => [$title('Went {answer} at {tracker:heat}')]],
                    ], 'skip' => 'left'],
                    $prompt('middle', 'Middle', ['next' => 'end', 'effects' => [$tracker('heat', 'add', 3)]]),
                    $prompt('west', 'West at {tracker:heat}', ['prompt' => 'You chose {step:way}', 'tip' => 'Mind the {Gap}']),
                ]),
                $sceneType('watch', [
                    ['key' => 'check', 'kind' => 'condition', 'title' => 'How hot is it?', 'tracker' => 'heat', 'effects' => [$tracker('alarm', 'add', 1)], 'bands' => [
                        ['upTo' => 1, 'next' => 'calm'],
                        ['upTo' => ['tracker' => 'limit'], 'effects' => [['kind' => 'nextScene', 'sceneType' => 'search'], ['kind' => 'nextScene', 'sceneType' => 'ask']]],
                        ['next' => 'hot', 'effects' => [['kind' => 'endPhase']]],
                    ]],
                    $prompt('warm', 'Warm'), $prompt('calm', 'Calm'), $prompt('hot', 'Hot'),
                ]),
                $sceneType('heist', [$prompt('plan', 'What is the plan?', ['next' => 'later', 'effects' => [$switch('escape'), $tracker('heat', 'add', 1)]]), $prompt('skipped', 'Skipped'), $prompt('later', 'Later')]),
                $sceneType('escape', [$prompt('run', 'Run!', ['effects' => [$switch('heist'), $title('Run')]])]),
                $sceneType('again', [$prompt('again', 'Again?', ['effects' => [$switch('again')]])]),
                $sceneType('titles', [
                    $prompt('name', 'Name it', ['effects' => [$title('{answer}{answer}')]]),
                    ['key' => 'blank', 'kind' => 'table', 'title' => 'Roll', 'table' => 'loot', 'effects' => [$title('  {answer} ')]],
                ]),
            ],
            'flows' => [['key' => 'control', 'name' => 'Control', 'defaultView' => 'journal', 'oracles' => ['loot', 'traps', 'fate'], 'trackers' => ['heat', 'limit', 'alarm'], 'phases' => [
                ['key' => 'roam', 'name' => 'Roaming', 'mode' => 'loop', 'selection' => ['rule' => 'player', 'sceneTypes' => $sceneTypes]],
            ]]],
            'checks' => [],
        ];
    }
}
