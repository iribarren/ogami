<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunStatus;
use App\Play\Domain\Campaign\FlowRun\StepCannotBeSkipped;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\Campaign\Lists\Npc;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\Tracker;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shipped `percentile-horror` preset played through the Campaign: the letter, the four
 * investigation scenes with their branching checks, the loop that ends on clues or dread, the
 * confrontation and the epilogue. The investigator has Library use, Persuade, Psychology, Spot hidden,
 * Stealth and Power at 50, and a Sanity of 99 (the track starts full).
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(Campaign::class)]
final class PercentileHorrorPresetFlowTest extends FlowRunTestCase
{
    private GameSystemSnapshot $release;

    protected function setUp(): void
    {
        /** @var array<string, mixed> $content */
        $content = json_decode((string) file_get_contents(__DIR__.'/../../../../../../presets/percentile-horror.json'), true, flags: \JSON_THROW_ON_ERROR);
        $this->release = self::translate($content);
    }

    #[Test]
    public function theLetterNeedsYourInvestigatorAndARelativeAndFillsTheFacts(): void
    {
        $campaign = self::guided($this->release, 'kingsport-letter');
        $campaign->startSession(self::at(), $this->release);

        foreach (['you', 'relative'] as $mandatory) {
            try {
                $campaign->skipFlowStep($mandatory, $this->release, self::at());
                self::fail(\sprintf('"%s" is mandatory.', $mandatory));
            } catch (StepCannotBeSkipped $exception) {
                self::assertSame(\sprintf('Step "%s" is mandatory: complete it.', $mandatory), $exception->getMessage());
            }
            if ('you' === $mandatory) {
                $this->createInvestigator($campaign);
            }
        }

        $campaign->completeFlowStep('relative', self::prompt('Philippa Marsh'), $this->release, self::at());
        $campaign->completeFlowStep('hook', $this->rolled('letter-hooks', 1), $this->release, self::at());
        $campaign->completeFlowStep('where', $this->rolled('locations', 2), $this->release, self::at());
        $campaign->skipFlowStep('journey', $this->release, self::at());

        self::assertSame([
            'relative' => 'Philippa Marsh',
            'letter' => 'Come at once: I have found the missing pages and I am afraid of them',
            'place' => 'The old customs house, half empty since the war',
            'horror' => '',
        ], $campaign->factValues($this->release));
        self::assertSame([['Philippa Marsh', ['kin']]], array_map(static fn (Npc $npc): array => [$npc->name(), $npc->tags()], $campaign->npcs()));
        self::assertSame(['inv'], array_map(static fn (CharacterId $id): string => $id->toString(), $campaign->currentSession()?->party() ?? []));
    }

    #[Test]
    public function afterTheLetterThePlayerChoosesInvestigationScenes(): void
    {
        $campaign = $this->inTheInvestigation();

        self::assertSame(1, self::flowRunOf($campaign)->phaseIndex());
        $campaign->pickSceneType('archive', $this->release, self::at());
        self::assertSame('archive', $campaign->currentScene()?->sceneType());
    }

    /**
     * @return iterable<string, array{int, string, int, int}> the d100 against Library use 50, the step reached, the clues and the dread
     */
    public static function archiveSearches(): iterable
    {
        yield 'a critical success finds a clue' => [1, 'found-more', 1, 0];
        yield 'a regular success finds a clue' => [50, 'found-more', 1, 0];
        yield 'a failure is a dead end' => [51, 'dead-end', 0, 0];
        yield 'a fumble is a dead end and raises the dread' => [100, 'dead-end', 0, 1];
    }

    #[Test]
    #[DataProvider('archiveSearches')]
    public function theArchiveSearchIsALibraryUseCheck(int $die, string $step, int $clues, int $dread): void
    {
        $campaign = $this->inTheInvestigation();
        $campaign->pickSceneType('archive', $this->release, self::at());
        $campaign->skipFlowStep('look', $this->release, self::at());

        $campaign->completeFlowStep('search', $this->checked($campaign, 'search', 'library-use', $die), $this->release, self::at());

        self::assertSame(['play', $step, $clues, $dread], [...self::position($campaign), $campaign->trackerValue($this->tracker('clues')), $campaign->trackerValue($this->tracker('dread'))]);
    }

    #[Test]
    public function aPersuadeSuccessSkipsTheReadingAndAnswers(): void
    {
        $campaign = $this->inTheInterview();

        $campaign->completeFlowStep('ask', $this->checked($campaign, 'ask', 'persuade', 50), $this->release, self::at());

        self::assertSame(['learn', ['Edmund Hale', ['contact']]], [self::position($campaign)[1], [$campaign->npcs()[1]->name(), $campaign->npcs()[1]->tags()]]);
        $campaign->completeFlowStep('learn', self::prompt('The ledger is in the cannery'), $this->release, self::at());
        self::assertSame(1, $campaign->trackerValue($this->tracker('clues')));
    }

    /**
     * @return iterable<string, array{int, string, int}> the Psychology die, where the scene goes and the clues
     */
    public static function readings(): iterable
    {
        yield 'a hard success reads them and they answer' => [25, 'learn', 0];
        yield 'a failure and they clam up, leaving the interview' => [51, 'who', 0];
    }

    #[Test]
    #[DataProvider('readings')]
    public function aFailedPersuadeFallsBackToReadingThem(int $reading, string $step, int $clues): void
    {
        $campaign = $this->inTheInterview();

        $campaign->completeFlowStep('ask', $this->checked($campaign, 'ask', 'persuade', 90), $this->release, self::at());
        self::assertSame('read', self::position($campaign)[1]);
        $campaign->completeFlowStep('read', $this->checked($campaign, 'read', 'psychology', $reading), $this->release, self::at());

        if ('learn' === $step) {
            self::assertSame(['play', 'learn'], self::position($campaign));
        } else {
            // "end" leaves the play steps: the Scene is open, waiting to be ended, and no clue was won.
            self::assertSame(['open', null], self::position($campaign));
        }
        self::assertSame($clues, $campaign->trackerValue($this->tracker('clues')));
    }

    #[Test]
    public function theSiteNeedsAHardSuccessToSpotAndThenTestsYourNerve(): void
    {
        $campaign = $this->inTheSite();

        // 26 is a regular success at Spot hidden 50: not enough here, so the place keeps its secret.
        $campaign->completeFlowStep('spot', $this->checked($campaign, 'spot', 'spot-hidden', 26), $this->release, self::at());
        self::assertSame(['play', 'missed', 0], [...self::position($campaign), $campaign->trackerValue($this->tracker('clues'))]);

        $campaign = $this->inTheSite();
        $campaign->completeFlowStep('spot', $this->checked($campaign, 'spot', 'spot-hidden', 25), $this->release, self::at());
        self::assertSame(['find', 1], [self::position($campaign)[1], $campaign->trackerValue($this->tracker('clues'))]);
        $campaign->completeFlowStep('find', $this->rolled('clues', 1), $this->release, self::at());
        $campaign->completeFlowStep('reveal', $this->rolled('horrors', 1), $this->release, self::at());

        // Sanity 99: a 99 still passes, and a fumble raises the dread by 2.
        $campaign->completeFlowStep('shock', $this->checked($campaign, 'shock', 'sanity', 100), $this->release, self::at());
        self::assertSame(2, $campaign->trackerValue($this->tracker('dread')));
    }

    #[Test]
    public function aShakenInvestigatorFailingTheSanityCheckRaisesTheDreadByOne(): void
    {
        $campaign = $this->inTheSite();
        $campaign->completeFlowStep('spot', $this->checked($campaign, 'spot', 'spot-hidden', 25), $this->release, self::at());
        $campaign->completeFlowStep('find', $this->rolled('clues', 1), $this->release, self::at());
        $campaign->completeFlowStep('reveal', $this->rolled('horrors', 1), $this->release, self::at());
        $dread = $campaign->trackerValue($this->tracker('dread'));

        // At Sanity 40 a 55 is a plain failure (above 40, under the fumble line): the dread rises by 1, not 2.
        $campaign->completeFlowStep('shock', $this->checkedWith($campaign, 'shock', 'sanity', 55, ['sanity' => ['current' => 40, 'max' => 99]]), $this->release, self::at());

        self::assertSame($dread + 1, $campaign->trackerValue($this->tracker('dread')));
    }

    #[Test]
    public function aStrangeNightEventMovesTheDread(): void
    {
        $campaign = $this->inTheInvestigation();
        $campaign->pickSceneType('night', $this->release, self::at());

        // The first strange event is bells under the water: the dread rises.
        $campaign->completeFlowStep('event', $this->rolled('strange-events', 1), $this->release, self::at());
        self::assertSame(1, $campaign->trackerValue($this->tracker('dread')));

        $campaign->completeFlowStep('hide', $this->checked($campaign, 'hide', 'stealth', 50), $this->release, self::at());
        $campaign->completeFlowStep('glimpse', $this->rolled('horrors', 3), $this->release, self::at());
        $campaign->completeFlowStep('nerve', $this->checked($campaign, 'nerve', 'sanity', 99), $this->release, self::at());
        self::assertSame(1, $campaign->trackerValue($this->tracker('dread')));
    }

    #[Test]
    public function enoughCluesEndTheInvestigationAtTheEndOfTheScene(): void
    {
        $campaign = $this->inTheInvestigation();
        $campaign->setTrackerValue($this->tracker('clues'), 3, self::at());
        $campaign->pickSceneType('night', $this->release, self::at());
        $this->playTheNight($campaign);
        $campaign->endFlowScene(2, $this->release, self::at());

        // Three clues are not enough: the world turn runs and the player picks the next scene.
        self::assertSame(1, self::flowRunOf($campaign)->phaseIndex());

        $campaign->completeFlowStep('dusk', self::roll('1d6', 6), $this->release, self::at());
        $campaign->setTrackerValue($this->tracker('clues'), 4, self::at());
        $campaign->pickSceneType('night', $this->release, self::at());
        $this->playTheNight($campaign);
        // The scene number is read from the scene itself: the world turn between the nights may take one too.
        $campaign->endFlowScene($campaign->currentScene()?->number() ?? self::fail('No scene.'), $this->release, self::at());

        self::assertSame([2, 'confrontation'], [self::flowRunOf($campaign)->phaseIndex(), $campaign->currentScene()?->sceneType()]);
    }

    #[Test]
    public function aFullDreadClockEndsTheInvestigationToo(): void
    {
        $campaign = $this->inTheInvestigation();
        $campaign->setTrackerValue($this->tracker('dread'), 6, self::at());
        $campaign->pickSceneType('night', $this->release, self::at());
        $this->playTheNight($campaign);
        $campaign->endFlowScene(2, $this->release, self::at());

        self::assertSame([2, 'confrontation'], [self::flowRunOf($campaign)->phaseIndex(), $campaign->currentScene()?->sceneType()]);
    }

    #[Test]
    public function theWorldTurnRaisesTheDreadOnAOneOrATwo(): void
    {
        $campaign = $this->inTheInvestigation();
        $campaign->pickSceneType('night', $this->release, self::at());
        $this->playTheNight($campaign);
        $campaign->endFlowScene(2, $this->release, self::at());

        $campaign->completeFlowStep('dusk', self::roll('1d6', 2), $this->release, self::at());

        self::assertSame(1, $campaign->trackerValue($this->tracker('dread')));
    }

    /**
     * @return iterable<string, array{int, string}> the Power die against 50 and the step after the check (Hard needed)
     */
    public static function stands(): iterable
    {
        yield 'a hard success drives it back' => [25, 'banish'];
        yield 'a regular success is not enough' => [26, 'cost'];
        yield 'a failure pays the cost' => [60, 'cost'];
    }

    #[Test]
    #[DataProvider('stands')]
    public function theConfrontationNeedsAHardPowerSuccessAndEndsOnASanityCheck(int $die, string $step): void
    {
        $campaign = $this->atTheConfrontation();
        $campaign->skipFlowStep('arrive', $this->release, self::at());
        $campaign->completeFlowStep('horror', $this->rolled('horrors', 2), $this->release, self::at());

        $campaign->completeFlowStep('will', $this->checked($campaign, 'will', 'pow', $die), $this->release, self::at());

        self::assertSame($step, self::position($campaign)[1]);
        $campaign->skipFlowStep($step, $this->release, self::at());
        self::assertSame('final-shock', self::position($campaign)[1]);
        self::assertSame('Something vast and patient behind the cellar wall', $campaign->factValues($this->release)['horror']);
    }

    /**
     * @return iterable<string, array{int, string, string}> the dread, the epilogue step and its prompt
     */
    public static function endings(): iterable
    {
        yield 'a calm night' => [2, 'calm', 'You write the letter home about Come at once: I have found the missing pages and I am afraid of them. What do you tell Philippa Marsh?'];
        yield 'the nights follow you home' => [3, 'scarred', 'You leave Kingsport but not the dream. What do you still hear?'];
    }

    #[Test]
    #[DataProvider('endings')]
    public function theEpilogueDependsOnTheDreadAndTheFlowRunCompletes(int $dread, string $step, string $prompt): void
    {
        $campaign = $this->atTheConfrontation();
        $campaign->setTrackerValue($this->tracker('dread'), $dread, self::at());
        $campaign->skipFlowStep('arrive', $this->release, self::at());
        $campaign->skipFlowStep('horror', $this->release, self::at());
        $campaign->skipFlowStep('will', $this->release, self::at());
        $campaign->skipFlowStep('cost', $this->release, self::at());
        $campaign->skipFlowStep('final-shock', $this->release, self::at());
        $campaign->endFlowScene(3, $this->release, self::at());

        self::assertSame(['epilogue', 'setup', $step, $prompt], [$campaign->currentScene()?->sceneType(), ...self::position($campaign), $campaign->flowRunView($this->release)?->step?->prompt]);
        $campaign->skipFlowStep($step, $this->release, self::at());
        $campaign->endFlowScene(4, $this->release, self::at());

        self::assertSame(FlowRunStatus::Completed, self::flowRunOf($campaign)->status());
    }

    private function tracker(string $key): Tracker
    {
        return $this->release->tracker($key) ?? self::fail(\sprintf('No tracker "%s".', $key));
    }

    /**
     * The investigator rolling the check with the d100 scripted.
     */
    private function checked(Campaign $campaign, string $stepKey, string $check, int $die): StepResult
    {
        $campaign->flowCheckDifficulty($stepKey, 'inv', null, $this->release, self::at());
        $investigator = $campaign->character(CharacterId::fromString('inv')) ?? self::fail('No investigator.');
        $values = SheetValues::of($this->release->sheet(), $investigator->values(), $campaign->trackerValuesOf($this->release));

        return StepResult::check('inv', ($this->release->check($check) ?? self::fail('No check.'))->perform(new ScriptedRandomNumberGenerator($die), $values, null, 0));
    }

    /**
     * Like checked(), but the investigator's sheet is overridden: a track's current value, for instance.
     *
     * @param array<string, mixed> $overrides
     */
    private function checkedWith(Campaign $campaign, string $stepKey, string $check, int $die, array $overrides): StepResult
    {
        $campaign->flowCheckDifficulty($stepKey, 'inv', null, $this->release, self::at());
        $investigator = $campaign->character(CharacterId::fromString('inv')) ?? self::fail('No investigator.');
        $values = SheetValues::of($this->release->sheet(), [...$investigator->values(), ...$overrides], $campaign->trackerValuesOf($this->release));

        return StepResult::check('inv', ($this->release->check($check) ?? self::fail('No check.'))->perform(new ScriptedRandomNumberGenerator($die), $values, null, 0));
    }

    private function rolled(string $table, int $total): StepResult
    {
        return StepResult::table($this->release->resolveOracleTable($table, new ScriptedRandomNumberGenerator($total)));
    }

    private function createInvestigator(Campaign $campaign): void
    {
        $campaign->addCharacter(CharacterId::fromString('inv'), 'Inv', [], [], ['occupation' => 'Reporter', 'library-use' => 50, 'persuade' => 50, 'psychology' => 50, 'spot-hidden' => 50, 'stealth' => 50, 'pow' => 50], $this->release);
        $campaign->completeFlowStep('you', StepResult::character('inv'), $this->release, self::at());
    }

    /**
     * A campaign past the letter scene, at the investigation phase's scene pick.
     */
    private function inTheInvestigation(): Campaign
    {
        $campaign = self::guided($this->release, 'kingsport-letter');
        $campaign->startSession(self::at(), $this->release);
        $this->createInvestigator($campaign);
        $campaign->completeFlowStep('relative', self::prompt('Philippa Marsh'), $this->release, self::at());
        $campaign->completeFlowStep('hook', $this->rolled('letter-hooks', 1), $this->release, self::at());
        $campaign->completeFlowStep('where', $this->rolled('locations', 2), $this->release, self::at());
        $campaign->skipFlowStep('journey', $this->release, self::at());
        $campaign->endFlowScene(1, $this->release, self::at());

        return $campaign;
    }

    private function inTheInterview(): Campaign
    {
        $campaign = $this->inTheInvestigation();
        $campaign->pickSceneType('interview', $this->release, self::at());
        $campaign->completeFlowStep('who', self::prompt('Edmund Hale'), $this->release, self::at());

        return $campaign;
    }

    private function inTheSite(): Campaign
    {
        $campaign = $this->inTheInvestigation();
        $campaign->pickSceneType('site', $this->release, self::at());
        $campaign->skipFlowStep('enter', $this->release, self::at());
        $campaign->completeFlowStep('alone', self::oracle(\App\Randomness\Domain\Oracle\YesNoAnswer::No), $this->release, self::at());

        return $campaign;
    }

    private function playTheNight(Campaign $campaign): void
    {
        foreach (['event', 'hide', 'glimpse', 'nerve'] as $key) {
            $campaign->skipFlowStep($key, $this->release, self::at());
        }
    }

    /**
     * Ends the investigation on four clues: the confrontation Scene starts by itself.
     */
    private function atTheConfrontation(): Campaign
    {
        $campaign = $this->inTheInvestigation();
        $campaign->setTrackerValue($this->tracker('clues'), 4, self::at());
        $campaign->pickSceneType('night', $this->release, self::at());
        $this->playTheNight($campaign);
        $campaign->endFlowScene(2, $this->release, self::at());

        return $campaign;
    }
}
