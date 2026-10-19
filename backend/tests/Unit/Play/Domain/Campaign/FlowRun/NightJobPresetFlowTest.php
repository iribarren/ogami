<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunStatus;
use App\Play\Domain\Campaign\FlowRun\PickCandidate;
use App\Play\Domain\Campaign\FlowRun\PickedItem;
use App\Play\Domain\Campaign\FlowRun\StepCannotBeSkipped;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\Campaign\Lists\Npc;
use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shipped `night-job` preset (flow example 2 with the preset's own tables and steps) played
 * through the Campaign: the briefing facts, the alarm clock, the switches, the escape and Payday.
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(Campaign::class)]
final class NightJobPresetFlowTest extends FlowRunTestCase
{
    private GameSystemSnapshot $release;

    protected function setUp(): void
    {
        /** @var array<string, mixed> $content */
        $content = json_decode((string) file_get_contents(__DIR__.'/../../../../../../presets/night-job.json'), true, flags: \JSON_THROW_ON_ERROR);
        $this->release = self::translate($content);
    }

    #[Test]
    public function theCrewSceneStartsWithAMandatoryCharacterStepThatTakesTheCharacterIntoTheParty(): void
    {
        $campaign = self::guided($this->release, 'heist');
        $campaign->startSession(self::at(), $this->release);
        $campaign->pickSceneType('crew', $this->release, self::at());

        self::assertSame(['setup', 'you'], self::position($campaign));
        try {
            $campaign->skipFlowStep('you', $this->release, self::at());
            self::fail('Your own Character is mandatory.');
        } catch (StepCannotBeSkipped $exception) {
            self::assertSame('Step "you" is mandatory: complete it.', $exception->getMessage());
        }

        $this->createYourCharacter($campaign);

        self::assertSame(['setup', 'first'], self::position($campaign));
        self::assertSame(['Vi'], array_map(static fn (\App\Play\Domain\Campaign\Characters\Character $character): string => $character->name(), $campaign->characters()));
        self::assertSame(['vi'], array_map(static fn (CharacterId $id): string => $id->toString(), $campaign->currentSession()?->party() ?? []));
    }

    #[Test]
    public function theBriefingFillsTheFourFactsAndTheCrewAndPlanAreMandatory(): void
    {
        $campaign = self::guided($this->release, 'heist');
        $campaign->startSession(self::at(), $this->release);
        $campaign->pickSceneType('crew', $this->release, self::at());
        $this->createYourCharacter($campaign);

        try {
            $campaign->skipFlowStep('first', $this->release, self::at());
            self::fail('The first crew member is mandatory.');
        } catch (StepCannotBeSkipped $exception) {
            self::assertSame('Step "first" is mandatory: complete it.', $exception->getMessage());
        }

        $campaign->completeFlowStep('first', self::prompt('Ada, a netrunner'), $this->release, self::at());
        $campaign->skipFlowStep('second', $this->release, self::at());
        $campaign->skipFlowStep('wheelman', $this->release, self::at());
        $campaign->endFlowScene(1, $this->release, self::at());

        $campaign->pickSceneType('briefing', $this->release, self::at());
        $campaign->completeFlowStep('client', $this->rolled('job-clients', 1), $this->release, self::at());
        $campaign->completeFlowStep('target', $this->rolled('job-targets', 2), $this->release, self::at());
        $campaign->completeFlowStep('payout', $this->rolled('payouts', 4), $this->release, self::at());
        try {
            $campaign->skipFlowStep('plan', $this->release, self::at());
            self::fail('The plan is mandatory.');
        } catch (StepCannotBeSkipped $exception) {
            self::assertSame('Step "plan" is mandatory: complete it.', $exception->getMessage());
        }

        $campaign->completeFlowStep('plan', self::prompt('In through the roof'), $this->release, self::at());

        self::assertSame([
            'client' => 'A fixer who needs a deniable crew',
            'target' => 'A data shard in a penthouse vault',
            'payout' => '1,000 eb',
            'plan' => 'In through the roof',
        ], $campaign->factValues($this->release));
        self::assertSame(['Ada, a netrunner', 'A fixer who needs a deniable crew'], array_map(static fn (Npc $npc): string => $npc->name(), $campaign->npcs()));
        self::assertSame([['crew'], ['client']], array_map(static fn (Npc $npc): array => $npc->tags(), $campaign->npcs()));
    }

    /**
     * @return iterable<string, array{int, bool}> the alarm and whether the heist scene asks what you notice
     */
    public static function alarmLevels(): iterable
    {
        yield 'a quiet alarm' => [3, false];
        yield 'a raised alarm' => [4, true];
        yield 'a high alarm' => [5, true];
    }

    #[Test]
    #[DataProvider('alarmLevels')]
    public function aHeistSceneOpensOnlyOnARaisedAlarm(int $alarm, bool $onEdge): void
    {
        $campaign = $this->inTheHeist();
        $campaign->setTrackerValue($this->release->tracker('alarm') ?? self::fail('No alarm.'), $alarm, self::at());

        $campaign->pickSceneType('grab', $this->release, self::at());

        if ($onEdge) {
            self::assertSame(['sceneOpening', 'notice', \sprintf('The alarm is at %d. What do you notice?', $alarm)], [...self::position($campaign), $campaign->flowRunView($this->release)?->step?->prompt]);
        } else {
            // A quiet alarm ends the scene opening: the scene goes straight to its play steps.
            self::assertSame(['play', 'reach'], self::position($campaign));
        }
    }

    /**
     * @return iterable<string, array{int, int, string}> the die, the Alarm and the band: Vi adds 4 (hands) to the die, against 13 + the Alarm
     */
    public static function slipPasts(): iterable
    {
        yield 'a clean success' => [9, 0, 'Success'];
        yield 'a fail at the lowest difficulty' => [5, 0, 'Failed'];
        yield 'a botch' => [2, 0, 'Botched'];
        yield 'the same total fails once the alarm rose' => [9, 2, 'Failed'];
    }

    #[Test]
    #[DataProvider('slipPasts')]
    public function slippingPastIsAHandsCheckAgainstTheAlarm(int $die, int $alarm, string $band): void
    {
        $campaign = $this->inInfiltration();
        $campaign->setTrackerValue($this->alarm(), $alarm, self::at());

        $campaign->completeFlowStep('slip-past', $this->checked($campaign, 'slip-past', 'hands', $die), $this->release, self::at());

        self::assertSame($band, self::flowRunOf($campaign)->answers()['slip-past']);
    }

    #[Test]
    public function theSlipPastDifficultyFollowsTheAlarm(): void
    {
        $campaign = $this->inInfiltration();
        $difficulty = fn (): ?int => $campaign->flowRunView($this->release)?->step?->check?->difficulty;

        $quiet = $difficulty();
        $campaign->setTrackerValue($this->alarm(), 5, self::at());

        self::assertSame([13, 18], [$quiet, $difficulty()]);
        self::assertTrue($campaign->flowRunView($this->release)?->step?->check?->difficultyFixed);
    }

    #[Test]
    public function aSuccessfulSlipPastContinuesWithoutRaisingTheAlarm(): void
    {
        $campaign = $this->inInfiltration();

        $campaign->completeFlowStep('slip-past', $this->checked($campaign, 'slip-past', 'hands', 9), $this->release, self::at());

        self::assertSame([0, 'infiltration'], [$campaign->trackerValue($this->alarm()), $campaign->currentScene()?->sceneType()]);
    }

    #[Test]
    public function aFailedSlipPastRaisesTheAlarm(): void
    {
        $campaign = $this->inInfiltration();

        $campaign->completeFlowStep('slip-past', $this->checked($campaign, 'slip-past', 'hands', 5), $this->release, self::at());

        self::assertSame([1, 'infiltration'], [$campaign->trackerValue($this->alarm()), $campaign->currentScene()?->sceneType()]);
    }

    #[Test]
    public function aBotchedSlipPastSwitchesTheInfiltrationIntoAFirefight(): void
    {
        $campaign = $this->inInfiltration();

        $campaign->completeFlowStep('slip-past', $this->checked($campaign, 'slip-past', 'hands', 2), $this->release, self::at());

        self::assertSame(['setup', 'who-shoots', 'firefight'], [...self::position($campaign), $campaign->currentScene()?->sceneType()]);
        $campaign->completeFlowStep('who-shoots', self::prompt('Ada'), $this->release, self::at());
        self::assertSame('Firefight: Ada', $campaign->currentScene()?->title());
    }

    #[Test]
    public function losingThemIsACoolCheckWhoseDifficultyThePlayerSets(): void
    {
        $campaign = $this->inTheHeist();
        $this->grabIt($campaign);
        $campaign->completeFlowStep('wheel', StepResult::pick(ItemKind::Npc, []), $this->release, self::at());
        $campaign->skipFlowStep('you-drive', $this->release, self::at());
        $view = $campaign->flowRunView($this->release)?->step?->check;

        // Vi's Cool is under 4 once the alarm is up: 9 + Cool stays under the difficulty 15, a fail.
        $campaign->completeFlowStep('lose-them', $this->checked($campaign, 'lose-them', 'cool', 9, 15), $this->release, self::at());

        self::assertSame([null, false, 'Failed'], [$view?->difficulty, $view->difficultyFixed ?? true, self::flowRunOf($campaign)->answers()['lose-them']]);
    }

    /**
     * @return iterable<string, array{int, string}> the net-floors roll (12 entries, the fifth is the Black ICE) and the step it leads to
     */
    public static function floors(): iterable
    {
        yield 'a password gate' => [1, 'jack'];
        yield 'Black ICE' => [5, 'ice'];
        yield 'a backdoor left by someone else' => [12, 'jack'];
    }

    #[Test]
    #[DataProvider('floors')]
    public function aNetFloorAsksForTheBlackIceOnlyWhenItHoldsBlackIce(int $roll, string $next): void
    {
        $campaign = $this->inTheHeist();
        $campaign->pickSceneType('netrun', $this->release, self::at());

        $campaign->completeFlowStep('floor', $this->rolled('net-floors', $roll), $this->release, self::at());

        self::assertSame(['play', $next], self::position($campaign));
    }

    /**
     * @return iterable<string, array{int, int, string, int, int}> the die, the difficulty, the step it leads to, the objective and the edge afterwards
     */
    public static function chases(): iterable
    {
        yield 'a botch loses the score' => [1, 20, 'boxed-in', 0, 2];
        yield 'a fail costs an edge' => [9, 15, 'shaken', 1, 1];
        yield 'a success costs nothing' => [10, 9, 'end', 1, 2];
    }

    #[Test]
    #[DataProvider('chases')]
    public function losingThemCostsTheScoreOnABotchAndAnEdgeOnAFail(int $die, int $difficulty, string $step, int $objective, int $edge): void
    {
        $campaign = $this->inTheHeist();
        $this->grabIt($campaign);
        $campaign->setTrackerValue($this->release->tracker('edge') ?? self::fail('No edge.'), 2, self::at());
        $campaign->completeFlowStep('wheel', StepResult::pick(ItemKind::Npc, []), $this->release, self::at());
        $campaign->skipFlowStep('you-drive', $this->release, self::at());

        $campaign->completeFlowStep('lose-them', $this->checked($campaign, 'lose-them', 'cool', $die, $difficulty), $this->release, self::at());

        $view = $campaign->flowRunView($this->release);
        self::assertSame('end' === $step ? null : $step, $view?->step?->step->key);
        self::assertSame([$objective, $edge], [$campaign->trackerValue($this->release->tracker('objective') ?? self::fail('No objective.')), $campaign->trackerValue($this->release->tracker('edge') ?? self::fail('No edge.'))]);
    }

    #[Test]
    public function theBotchedChaseNamesWhatItCostsAndTheFailedOneAsksWhatYouLeaveBehind(): void
    {
        foreach ([[1, 20, 'They box you in. You get away, but the score stays behind.'], [9, 15, 'Shaking them costs you. What do you leave behind?']] as [$die, $difficulty, $prompt]) {
            $campaign = $this->inTheHeist();
            $this->grabIt($campaign);
            $campaign->completeFlowStep('wheel', StepResult::pick(ItemKind::Npc, []), $this->release, self::at());
            $campaign->skipFlowStep('you-drive', $this->release, self::at());
            $campaign->completeFlowStep('lose-them', $this->checked($campaign, 'lose-them', 'cool', $die, $difficulty), $this->release, self::at());

            self::assertSame($prompt, $campaign->flowRunView($this->release)?->step?->prompt);
        }
    }

    #[Test]
    public function theGrabEndsTheHeistPhaseWhenYouGetTheObjectiveAndTheComplicationMovesTheAlarm(): void
    {
        $campaign = $this->inTheHeist();
        $campaign->setTrackerValue($this->alarm(), 4, self::at());
        $campaign->pickSceneType('grab', $this->release, self::at());
        $campaign->skipFlowStep('notice', $this->release, self::at());
        $campaign->skipFlowStep('reach', $this->release, self::at());
        $campaign->endFlowScene(5, $this->release, self::at());

        try {
            $campaign->skipFlowStep('got-it', $this->release, self::at());
            self::fail('Getting the objective is mandatory to settle.');
        } catch (StepCannotBeSkipped) {
        }

        $campaign->completeFlowStep('got-it', StepResult::choice('yes'), $this->release, self::at());
        self::assertSame(['sceneClosing', 'complication', 'endPhase', 1], [...self::position($campaign), self::flowRunOf($campaign)->phaseEndingReason(), $campaign->trackerValue($this->release->tracker('objective') ?? self::fail('No objective.'))]);

        // The first entry of the complications table is a patrol: the alarm rises.
        $campaign->completeFlowStep('complication', $this->rolled('complications', 1), $this->release, self::at());
        self::assertSame([3, 5], [self::flowRunOf($campaign)->phaseIndex(), $campaign->trackerValue($this->alarm())]);
    }

    /**
     * @return iterable<string, array{bool, string}> whether the crew has a driver, and the Chase prompt
     */
    public static function drivers(): iterable
    {
        yield 'a nomad driver in the crew' => [true, 'Rook takes the wheel.'];
        yield 'nobody else can drive' => [false, 'You take the wheel.'];
    }

    #[Test]
    #[DataProvider('drivers')]
    public function aHotGetawayTurnsIntoAChaseWhoseWheelGoesToTheCrewNomadOrYou(bool $driver, string $prompt): void
    {
        $campaign = $this->inTheHeist($driver);
        $this->grabIt($campaign);

        self::assertSame(['Getaway 1', 'chase', 'setup', 'wheel'], [$campaign->currentScene()?->title(), $campaign->currentScene()?->sceneType(), ...self::position($campaign)]);
        // The candidates are the ones Play computes: crew AND nomad, so neither the crew without the
        // nomad tag nor the client qualify.
        $candidates = ($campaign->flowRunView($this->release)?->step->pick ?? self::fail('The wheel offers no pick.'))->candidates;
        self::assertSame($driver ? ['Rook'] : [], array_map(static fn (PickCandidate $candidate): string => $candidate->name, $candidates));
        $campaign->completeFlowStep('wheel', StepResult::pick(ItemKind::Npc, array_map(static fn (PickCandidate $candidate): PickedItem => new PickedItem($candidate->id, $candidate->name), $candidates)), $this->release, self::at());

        self::assertSame($driver ? ['setup', 'driver'] : ['setup', 'you-drive'], self::position($campaign));
        self::assertSame($prompt, $campaign->flowRunView($this->release)?->step?->prompt);
    }

    #[Test]
    public function aQuietGetawayStaysAGetaway(): void
    {
        $campaign = $this->inTheHeist();
        $campaign->setTrackerValue($this->alarm(), 3, self::at());
        $campaign->pickSceneType('grab', $this->release, self::at());
        $campaign->skipFlowStep('reach', $this->release, self::at());
        $campaign->endFlowScene(5, $this->release, self::at());
        $campaign->completeFlowStep('got-it', StepResult::choice('yes'), $this->release, self::at());
        $campaign->skipFlowStep('complication', $this->release, self::at());

        self::assertSame(['getaway', 'slip-out'], [$campaign->currentScene()?->sceneType(), self::position($campaign)[1]]);
    }

    #[Test]
    public function paydayQuotesThePayoutAndTheFlowRunCompletes(): void
    {
        $campaign = $this->inTheHeist(true);
        $this->grabIt($campaign);
        $campaign->completeFlowStep('wheel', StepResult::pick(ItemKind::Npc, [new PickedItem($campaign->npcs()[1]->id()->toString(), 'Rook')]), $this->release, self::at());
        $campaign->skipFlowStep('driver', $this->release, self::at());
        $campaign->skipFlowStep('lose-them', $this->release, self::at());
        $campaign->endFlowScene(6, $this->release, self::at());

        self::assertSame(['setup', 'paid', 'The client pays 300 eb and a handshake. Was the job worth it?'], [...self::position($campaign), $campaign->flowRunView($this->release)?->step?->prompt]);
        $campaign->completeFlowStep('paid', self::prompt('Barely'), $this->release, self::at());
        $campaign->completeFlowStep('double-cross', self::roll('1d6', 6), $this->release, self::at());
        $campaign->endFlowScene(7, $this->release, self::at());

        self::assertSame(FlowRunStatus::Completed, self::flowRunOf($campaign)->status());
    }

    #[Test]
    public function withoutTheObjectiveThereIsNoPay(): void
    {
        $campaign = $this->inTheHeist();
        $this->grabIt($campaign);
        $campaign->setTrackerValue($this->release->tracker('objective') ?? self::fail('No objective.'), 0, self::at());
        $campaign->completeFlowStep('wheel', StepResult::pick(ItemKind::Npc, []), $this->release, self::at());
        $campaign->skipFlowStep('you-drive', $this->release, self::at());
        $campaign->skipFlowStep('lose-them', $this->release, self::at());
        $campaign->endFlowScene(6, $this->release, self::at());

        self::assertSame(['setup', 'no-pay'], self::position($campaign));
        $campaign->completeFlowStep('no-pay', self::prompt('We owe the crew'), $this->release, self::at());
        $campaign->endFlowScene(7, $this->release, self::at());
        self::assertSame(FlowRunStatus::Completed, self::flowRunOf($campaign)->status());
    }

    #[Test]
    public function aDoubleCrossRollSendsYouToTheBetrayal(): void
    {
        $campaign = $this->inTheHeist();
        $this->grabIt($campaign);
        $campaign->completeFlowStep('wheel', StepResult::pick(ItemKind::Npc, []), $this->release, self::at());
        $campaign->skipFlowStep('you-drive', $this->release, self::at());
        $campaign->skipFlowStep('lose-them', $this->release, self::at());
        $campaign->endFlowScene(6, $this->release, self::at());
        $campaign->completeFlowStep('paid', self::prompt('Barely'), $this->release, self::at());

        // The roll decides between the betrayal and the end: the preview does not name the betrayal.
        self::assertSame('decided by the roll', $campaign->flowRunView($this->release)?->next);

        $campaign->completeFlowStep('double-cross', self::roll('1d6', 1), $this->release, self::at());

        self::assertSame(['setup', 'betrayed'], self::position($campaign));
    }

    /**
     * The result of the Character Vi rolling the check with the die scripted: the difficulty is the
     * step's when it fixes one, else the one the player gives.
     */
    private function checked(Campaign $campaign, string $stepKey, string $check, int $die, ?int $given = null): StepResult
    {
        $difficulty = $campaign->flowCheckDifficulty($stepKey, 'vi', $given, $this->release, self::at());
        $vi = $campaign->character(CharacterId::fromString('vi')) ?? self::fail('No Vi.');
        $values = SheetValues::of($this->release->sheet(), $vi->values(), $campaign->trackerValuesOf($this->release));

        return StepResult::check('vi', ($this->release->check($check) ?? self::fail('No check.'))->perform(new ScriptedRandomNumberGenerator($die), $values, $difficulty, 0));
    }

    private function inInfiltration(): Campaign
    {
        $campaign = $this->inTheHeist();
        $campaign->pickSceneType('infiltration', $this->release, self::at());
        $campaign->skipFlowStep('approach', $this->release, self::at());

        return $campaign;
    }

    private function alarm(): \App\Play\Domain\GameSystem\Tracker
    {
        return $this->release->tracker('alarm') ?? self::fail('No alarm.');
    }

    /**
     * Grabs the objective on a raised alarm: the heist phase ends, and the high alarm turns the
     * getaway into a chase, waiting at its "wheel" step.
     */
    private function grabIt(Campaign $campaign): void
    {
        $campaign->setTrackerValue($this->alarm(), 4, self::at());
        $campaign->pickSceneType('grab', $this->release, self::at());
        $campaign->skipFlowStep('notice', $this->release, self::at());
        $campaign->skipFlowStep('reach', $this->release, self::at());
        $campaign->endFlowScene(5, $this->release, self::at());
        $campaign->completeFlowStep('got-it', StepResult::choice('yes'), $this->release, self::at());
        $campaign->completeFlowStep('complication', $this->rolled('complications', 1), $this->release, self::at());
    }

    /**
     * Creates the Character the player makes in the crew scene, with its sheet defaults, and completes the first step with it.
     */
    private function createYourCharacter(Campaign $campaign): void
    {
        $campaign->addCharacter(CharacterId::fromString('vi'), 'Vi', [], [], ['role' => 'Netrunner'], $this->release);
        $campaign->completeFlowStep('you', StepResult::character('vi'), $this->release, self::at());
    }

    private function rolled(string $table, int $total): StepResult
    {
        return StepResult::table($this->release->resolveOracleTable($table, new ScriptedRandomNumberGenerator($total)));
    }

    /**
     * A campaign with the crew and the briefing done, past the legwork, at the heist phase's scene pick.
     */
    private function inTheHeist(bool $driver = false): Campaign
    {
        $campaign = self::guided($this->release, 'heist');
        $campaign->startSession(self::at(), $this->release);
        $campaign->pickSceneType('crew', $this->release, self::at());
        $this->createYourCharacter($campaign);
        $campaign->completeFlowStep('first', self::prompt('Ada, a netrunner'), $this->release, self::at());
        $campaign->skipFlowStep('second', $this->release, self::at());
        if ($driver) {
            $campaign->completeFlowStep('wheelman', self::prompt('Rook'), $this->release, self::at());
        } else {
            $campaign->skipFlowStep('wheelman', $this->release, self::at());
        }

        $campaign->endFlowScene(1, $this->release, self::at());
        $campaign->pickSceneType('briefing', $this->release, self::at());
        $campaign->completeFlowStep('client', $this->rolled('job-clients', 1), $this->release, self::at());
        $campaign->completeFlowStep('target', $this->rolled('job-targets', 2), $this->release, self::at());
        $campaign->completeFlowStep('payout', $this->rolled('payouts', 1), $this->release, self::at());
        $campaign->completeFlowStep('plan', self::prompt('In through the roof'), $this->release, self::at());
        $campaign->skipFlowStep('catch', $this->release, self::at());
        $campaign->endFlowScene(2, $this->release, self::at());

        $campaign->pickSceneType('social', $this->release, self::at());
        $campaign->skipFlowStep('talk', $this->release, self::at());
        $campaign->skipFlowStep('reaction', $this->release, self::at());
        $campaign->endFlowScene(3, $this->release, self::at());
        $campaign->completeFlowStep('gain-edge', StepResult::choice('no'), $this->release, self::at());
        $campaign->moveOn('legwork', $this->release, self::at());
        $campaign->completeFlowStep('word', self::roll('1d6', 6), $this->release, self::at());

        return $campaign;
    }
}
