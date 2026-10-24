<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\CreationMethod;
use App\Play\Domain\Campaign\Characters\CreationRefusal;
use App\Play\Domain\Campaign\Characters\InvalidCharacter;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunStage;
use App\Play\Domain\Campaign\FlowRun\FlowRunStatus;
use App\Play\Domain\Campaign\FlowRun\PickedItem;
use App\Play\Domain\Campaign\FlowRun\ScenePart;
use App\Play\Domain\Campaign\FlowRun\SheetChange;
use App\Play\Domain\Campaign\FlowRun\StepCannotBeSkipped;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\Campaign\Lists\Npc;
use App\Play\Domain\Campaign\Lists\Thread;
use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\Tracker;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shipped `percentile-horror` preset played through the Campaign: the prologue, the four
 * investigation scenes with their branching checks, the loop that ends on clues or dread, the
 * confrontation and the epilogue. The investigator has Library use, Persuade, Psychology, Spot hidden,
 * Stealth and Power at 50 (unless a test says otherwise) and 12 Hit points; choosing them at the
 * character step sets their Sanity to their Power and rolls their Luck. Creation (ADR 0040) rolls or
 * spends points on the eight characteristics and starts Hit and Magic points full.
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(Campaign::class)]
final class PercentileHorrorPresetFlowTest extends FlowRunTestCase
{
    private GameSystemSnapshot $release;

    /** The investigator's Power, which the character step turns into Sanity. */
    private int $pow = 50;

    /** The investigator's Hit points, current and max. */
    private int $hp = 12;

    protected function setUp(): void
    {
        /** @var array<string, mixed> $content */
        $content = json_decode((string) file_get_contents(__DIR__.'/../../../../../../presets/percentile-horror.json'), true, flags: \JSON_THROW_ON_ERROR);
        $this->release = self::translate($content);
    }

    #[Test]
    public function thePrologueNeedsYourInvestigatorAContactAHookThePlaceAndTheMysteryAndFillsTheFacts(): void
    {
        $campaign = self::guided($this->release, 'kingsport-mystery');
        $campaign->startSession(self::at(), $this->release);

        foreach (['you', 'contact', 'hook', 'where', 'mystery'] as $mandatory) {
            try {
                $campaign->skipFlowStep($mandatory, $this->release, self::at());
                self::fail(\sprintf('"%s" is mandatory.', $mandatory));
            } catch (StepCannotBeSkipped $exception) {
                self::assertSame(\sprintf('Step "%s" is mandatory: complete it.', $mandatory), $exception->getMessage());
            }
            match ($mandatory) {
                'you' => $this->createInvestigator($campaign),
                'contact' => $campaign->completeFlowStep('contact', self::prompt('Philippa Marsh'), $this->release, self::at()),
                'hook' => $campaign->completeFlowStep('hook', self::prompt('My aunt writes that the house is not safe'), $this->release, self::at()),
                'where' => $campaign->completeFlowStep('where', self::prompt('The old customs house'), $this->release, self::at()),
                default => null,
            };
        }

        $campaign->completeFlowStep('mystery', self::prompt('Who emptied my aunt\'s cellar?'), $this->release, self::at());
        $campaign->skipFlowStep('journey', $this->release, self::at());

        self::assertSame([
            'contact' => 'Philippa Marsh',
            'hook' => 'My aunt writes that the house is not safe',
            'place' => 'The old customs house',
            'lair' => '',
            'horror' => '',
        ], $campaign->factValues($this->release));
        self::assertSame([['Philippa Marsh', ['contact']]], array_map(static fn (Npc $npc): array => [$npc->name(), $npc->tags()], $campaign->npcs()));
        self::assertSame([['Who emptied my aunt\'s cellar?', ['mystery'], true]], array_map(static fn (Thread $thread): array => [$thread->name(), $thread->tags(), $thread->isOpen()], $campaign->threads()));
        self::assertSame(['inv'], array_map(static fn (CharacterId $id): string => $id->toString(), $campaign->currentSession()?->party() ?? []));
    }

    #[Test]
    public function theJourneyIsSuggestedAndCanBeSkipped(): void
    {
        $campaign = self::guided($this->release, 'kingsport-mystery');
        $campaign->startSession(self::at(), $this->release);
        $this->createInvestigator($campaign);
        $campaign->completeFlowStep('contact', self::prompt('Philippa Marsh'), $this->release, self::at());
        $campaign->completeFlowStep('hook', self::prompt('A telegram'), $this->release, self::at());
        $campaign->completeFlowStep('where', self::prompt('The old customs house'), $this->release, self::at());
        $campaign->completeFlowStep('mystery', self::prompt('Who emptied my aunt\'s cellar?'), $this->release, self::at());

        $campaign->skipFlowStep('journey', $this->release, self::at());

        self::assertSame('The old customs house', $campaign->factValues($this->release)['place']);
        // The setup steps are done: the Setup scene stays open for free play, still in the prologue.
        $flowRun = self::flowRunOf($campaign);
        self::assertSame([0, FlowRunStage::Scene, ScenePart::Open, null], [$flowRun->phaseIndex(), $flowRun->stage(), $flowRun->part(), $flowRun->stepKey()]);
    }

    #[Test]
    public function afterThePrologueThePlayerChoosesInvestigationScenes(): void
    {
        $campaign = $this->inTheInvestigation();

        self::assertSame(1, self::flowRunOf($campaign)->phaseIndex());
        $campaign->pickSceneType('archive', $this->release, self::at());
        self::assertSame('archive', $campaign->currentScene()?->sceneType());
    }

    #[Test]
    public function choosingTheInvestigatorSetsTheirSanityToTheirPowerAndRollsTheirLuck(): void
    {
        $this->pow = 65;
        $campaign = self::guided($this->release, 'kingsport-mystery');
        $campaign->startSession(self::at(), $this->release);

        // A new Character's Sanity and Luck tracks start full (99); the character step sets them.
        $campaign->addCharacter(CharacterId::fromString('inv'), 'Inv', [], [], $this->points(['pow' => $this->pow]), $this->release, CreationMethod::Points);
        self::assertSame([99, 99], [$this->current($campaign, 'sanity'), $this->current($campaign, 'luck')]);
        $changes = $campaign->completeFlowStep('you', StepResult::character('inv'), $this->release, self::at(), new ScriptedRandomNumberGenerator(4, 5, 6));

        self::assertEquals([new SheetChange('sanity', 99, 65), new SheetChange('luck', 99, 75, '3d6*5', 75)], $changes);
        self::assertSame([65, 75], [$this->current($campaign, 'sanity'), $this->current($campaign, 'luck')]);
    }

    #[Test]
    public function anInvestigatorRolledAtCreationStartsWithFullHitAndMagicPoints(): void
    {
        $campaign = self::guided($this->release, 'kingsport-mystery');
        $campaign->startSession(self::at(), $this->release);

        // In sheet order: Strength, Constitution, Size, Dexterity, Appearance, Intelligence, Power, Education.
        $campaign->addCharacter(CharacterId::fromString('inv'), 'Inv', [], [], ['occupation' => 'Reporter'], $this->release, CreationMethod::Roll, new ScriptedRandomNumberGenerator(
            6, 5, 4,
            4, 4, 4,
            3, 3,
            5, 5, 5,
            2, 2, 2,
            6, 6,
            4, 4, 4,
            1, 1,
        ));

        $values = $campaign->character(CharacterId::fromString('inv'))?->values() ?? self::fail('No investigator.');
        self::assertSame(
            ['str' => 75, 'con' => 60, 'siz' => 60, 'dex' => 75, 'app' => 30, 'int' => 90, 'pow' => 60, 'edu' => 40],
            array_intersect_key($values, array_flip(['str', 'con', 'siz', 'dex', 'app', 'int', 'pow', 'edu'])),
        );
        self::assertSame(['current' => 12, 'max' => 12], $values['hp']);
        self::assertSame(['current' => 12, 'max' => 12], $values['mp']);
    }

    #[Test]
    public function anInvestigatorBuiltWithPointsStartsWithFullHitAndMagicPoints(): void
    {
        $campaign = self::guided($this->release, 'kingsport-mystery');
        $campaign->startSession(self::at(), $this->release);

        $campaign->addCharacter(CharacterId::fromString('inv'), 'Inv', [], [], $this->points(['con' => 70, 'siz' => 40, 'pow' => 85]), $this->release, CreationMethod::Points);

        $values = $campaign->character(CharacterId::fromString('inv'))?->values() ?? self::fail('No investigator.');
        self::assertSame(['current' => 11, 'max' => 11], $values['hp']);
        self::assertSame(['current' => 17, 'max' => 17], $values['mp']);
    }

    #[Test]
    public function spendingMoreThan460PointsOrLeavingTheBoundsIsRefused(): void
    {
        $campaign = self::guided($this->release, 'kingsport-mystery');
        $campaign->startSession(self::at(), $this->release);

        foreach ([
            [['str' => 80, 'con' => 80, 'siz' => 80, 'dex' => 80], CreationRefusal::BudgetExceeded],
            [['str' => 91], CreationRefusal::OutsideBounds],
            [['str' => 14], CreationRefusal::OutsideBounds],
        ] as [$overrides, $refusal]) {
            try {
                $campaign->addCharacter(CharacterId::fromString('inv'), 'Inv', [], [], $this->points($overrides), $this->release, CreationMethod::Points);
                self::fail('The Character was accepted.');
            } catch (InvalidCharacter $invalid) {
                self::assertSame($refusal, $invalid->refusal, $invalid->getMessage());
            }
        }
        self::assertNull($campaign->character(CharacterId::fromString('inv')));
    }

    #[Test]
    public function aValueForHitPointsIsRefusedBecauseCreationSetsIt(): void
    {
        $campaign = self::guided($this->release, 'kingsport-mystery');
        $campaign->startSession(self::at(), $this->release);

        try {
            $campaign->addCharacter(CharacterId::fromString('inv'), 'Inv', [], [], $this->points(['hp' => ['current' => 12, 'max' => 12]]), $this->release, CreationMethod::Points);
            self::fail('The Character was accepted.');
        } catch (InvalidCharacter $invalid) {
            self::assertSame(CreationRefusal::ValueSetAtCreation, $invalid->refusal, $invalid->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, int, ?int, int, int}> the Sanity step, the d100 against Sanity 65, the die of the loss rolled (none on a success), the Sanity lost and the dread raised
     */
    public static function sanityChecks(): iterable
    {
        foreach (['shock' => ['1d6', 6], 'nerve' => ['1d6', 6], 'final-shock' => ['1d10', 10]] as $step => [$dice, $fumbleLoss]) {
            $failureLoss = 4;
            yield $step.': a critical success costs 1' => [$step, 1, null, 1, 0];
            yield $step.': an extreme success costs 1' => [$step, 13, null, 1, 0];
            yield $step.': a hard success costs 1' => [$step, 32, null, 1, 0];
            yield $step.': a regular success costs 1' => [$step, 65, null, 1, 0];
            yield $step.': a failure costs '.$dice => [$step, 66, $failureLoss, $failureLoss, 'final-shock' === $step ? 0 : 1];
            yield $step.': a fumble costs '.$fumbleLoss => [$step, 100, null, $fumbleLoss, 'final-shock' === $step ? 0 : 2];
        }
    }

    #[Test]
    #[DataProvider('sanityChecks')]
    public function aSanityCheckCostsSanityByItsBand(string $step, int $die, ?int $rolled, int $lost, int $dread): void
    {
        $this->pow = 65;
        $campaign = $this->atTheSanityStep($step);
        $before = $campaign->trackerValue($this->tracker('dread'));

        $changes = $campaign->completeFlowStep($step, $this->checked($campaign, $step, 'sanity', $die), $this->release, self::at(), null === $rolled ? new ScriptedRandomNumberGenerator() : new ScriptedRandomNumberGenerator($rolled));

        self::assertSame([65 - $lost, $before + $dread], [$this->current($campaign, 'sanity'), $campaign->trackerValue($this->tracker('dread'))]);
        self::assertSame([['sanity', 65, 65 - $lost]], array_map(static fn (SheetChange $change): array => [$change->field, $change->before, $change->after], $changes));
    }

    /**
     * @return iterable<string, array{string, int}> the Sanity step and the die of the loss
     */
    public static function sanitySteps(): iterable
    {
        yield 'a failure at the site' => ['shock', 6];
        yield 'a failure at night' => ['nerve', 6];
        yield 'a failure at the end' => ['final-shock', 10];
    }

    #[Test]
    #[DataProvider('sanitySteps')]
    public function sanityDoesNotFallUnderZero(string $step, int $rolled): void
    {
        $this->pow = 3;
        $campaign = $this->atTheSanityStep($step);

        // A 50 fails at Sanity 3 and loses the whole die; a fumble would lose more than is left.
        $campaign->completeFlowStep($step, $this->checked($campaign, $step, 'sanity', 50), $this->release, self::at(), new ScriptedRandomNumberGenerator($rolled));

        self::assertSame(0, $this->current($campaign, 'sanity'));
    }

    #[Test]
    public function aFumbledSanityCheckAtLowSanityStopsAtZero(): void
    {
        $this->pow = 3;
        $campaign = $this->atTheSanityStep('nerve');

        $campaign->completeFlowStep('nerve', $this->checked($campaign, 'nerve', 'sanity', 100), $this->release, self::at());

        self::assertSame(0, $this->current($campaign, 'sanity'));
    }

    /**
     * @return iterable<string, array{int, ?int, int, int}> the d100 against Stealth 50, the die of the wound, the Hit points lost and the dread raised
     */
    public static function hides(): iterable
    {
        yield 'a success costs nothing' => [50, null, 0, 0];
        yield 'a failure raises the dread only' => [60, null, 0, 1];
        yield 'a fumble raises the dread by 2 and wounds' => [100, 5, 5, 2];
    }

    #[Test]
    #[DataProvider('hides')]
    public function slippingAwayUnseenMayCostDreadAndAWound(int $die, ?int $rolled, int $lost, int $dread): void
    {
        $campaign = $this->inTheInvestigation();
        $campaign->pickSceneType('night', $this->release, self::at());
        $campaign->completeFlowStep('event', self::prompt('Bells ring under the water'), $this->release, self::at());

        $campaign->completeFlowStep('hide', $this->checked($campaign, 'hide', 'stealth', $die), $this->release, self::at(), null === $rolled ? new ScriptedRandomNumberGenerator() : new ScriptedRandomNumberGenerator($rolled));

        self::assertSame([12 - $lost, $dread, 'glimpse'], [$this->current($campaign, 'hp'), $campaign->trackerValue($this->tracker('dread')), self::position($campaign)[1]]);
    }

    /**
     * @return iterable<string, array{int, string, ?int, int, int}> the d100 against Power 50, the step after, the die of the wound, the Hit points lost and the dread raised
     */
    public static function wills(): iterable
    {
        yield 'a hard success drives it back unhurt' => [25, 'banish', null, 0, 0];
        yield 'a regular success is not enough and wounds' => [26, 'cost', 3, 3, 0];
        yield 'a failure wounds' => [60, 'cost', 6, 6, 0];
        yield 'a fumble wounds and raises the dread by 2' => [100, 'cost', 2, 2, 2];
    }

    #[Test]
    #[DataProvider('wills')]
    public function holdingYourGroundCostsHitPointsUnlessItIsAHardSuccess(int $die, string $step, ?int $rolled, int $lost, int $dread): void
    {
        $campaign = $this->atTheConfrontation();
        $campaign->completeFlowStep('arrive', self::prompt('A cellar under the town hall'), $this->release, self::at());
        $campaign->skipFlowStep('horror', $this->release, self::at());

        $campaign->completeFlowStep('will', $this->checked($campaign, 'will', 'pow', $die), $this->release, self::at(), null === $rolled ? new ScriptedRandomNumberGenerator() : new ScriptedRandomNumberGenerator($rolled));

        self::assertSame([$step, 12 - $lost, $dread], [self::position($campaign)[1], $this->current($campaign, 'hp'), $campaign->trackerValue($this->tracker('dread'))]);
    }

    #[Test]
    public function hitPointsDoNotFallUnderZero(): void
    {
        $this->hp = 4;
        $campaign = $this->atTheConfrontation();
        $campaign->completeFlowStep('arrive', self::prompt('A cellar under the town hall'), $this->release, self::at());
        $campaign->skipFlowStep('horror', $this->release, self::at());

        // The d6 shows 6 on a character with 4 Hit points.
        $campaign->completeFlowStep('will', $this->checked($campaign, 'will', 'pow', 60), $this->release, self::at(), new ScriptedRandomNumberGenerator(6));

        self::assertSame(0, $this->current($campaign, 'hp'));
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
        $campaign->completeFlowStep('find', self::prompt('A tide table with one night circled'), $this->release, self::at());
        $campaign->completeFlowStep('reveal', self::prompt('Something waits below the tide line'), $this->release, self::at());

        // Sanity 50 (its Power): a fumble raises the dread by 2.
        $campaign->completeFlowStep('shock', $this->checked($campaign, 'shock', 'sanity', 100), $this->release, self::at());
        self::assertSame(2, $campaign->trackerValue($this->tracker('dread')));
    }

    #[Test]
    public function aShakenInvestigatorFailingTheSanityCheckRaisesTheDreadByOne(): void
    {
        $campaign = $this->inTheSite();
        $campaign->completeFlowStep('spot', $this->checked($campaign, 'spot', 'spot-hidden', 25), $this->release, self::at());
        $campaign->completeFlowStep('find', self::prompt('A key too old for any lock'), $this->release, self::at());
        $campaign->completeFlowStep('reveal', self::prompt('A door that opens on the wrong decade'), $this->release, self::at());
        $dread = $campaign->trackerValue($this->tracker('dread'));

        // At Sanity 40 a 55 is a plain failure (above 40, under the fumble line): the dread rises by 1, not 2.
        $campaign->completeFlowStep('shock', $this->checkedWith($campaign, 'shock', 'sanity', 55, ['sanity' => ['current' => 40, 'max' => 99]]), $this->release, self::at(), new ScriptedRandomNumberGenerator(3));

        self::assertSame($dread + 1, $campaign->trackerValue($this->tracker('dread')));
    }

    #[Test]
    public function aNightEventIsWrittenByThePlayerAndLeavesTheDreadToTheChecks(): void
    {
        $campaign = $this->inTheInvestigation();
        $campaign->pickSceneType('night', $this->release, self::at());

        // The strange events are inspiration now: writing the event moves nothing by itself.
        $campaign->completeFlowStep('event', self::prompt('Bells ring under the water'), $this->release, self::at());
        self::assertSame(0, $campaign->trackerValue($this->tracker('dread')));

        $campaign->completeFlowStep('hide', $this->checked($campaign, 'hide', 'stealth', 50), $this->release, self::at());
        $campaign->completeFlowStep('glimpse', self::prompt('A pale hand on the glass'), $this->release, self::at());
        $campaign->completeFlowStep('nerve', $this->checked($campaign, 'nerve', 'sanity', 50), $this->release, self::at());
        self::assertSame(0, $campaign->trackerValue($this->tracker('dread')));
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
        $campaign->completeFlowStep('arrive', self::prompt('A cellar under the town hall'), $this->release, self::at());
        $campaign->completeFlowStep('horror', self::prompt('Something vast behind the cellar wall'), $this->release, self::at());

        $campaign->completeFlowStep('will', $this->checked($campaign, 'will', 'pow', $die), $this->release, self::at(), new ScriptedRandomNumberGenerator(4));

        self::assertSame($step, self::position($campaign)[1]);
        $campaign->skipFlowStep($step, $this->release, self::at());
        self::assertSame('final-shock', self::position($campaign)[1]);
        self::assertSame(['A cellar under the town hall', 'Something vast behind the cellar wall'], [$campaign->factValues($this->release)['lair'], $campaign->factValues($this->release)['horror']]);
    }

    /**
     * @return iterable<string, array{int, string, string}> the dread, the epilogue step and its prompt
     */
    public static function endings(): iterable
    {
        yield 'a calm night' => [2, 'calm', 'You write home about what drew you here: My aunt writes that the house is not safe. What do you tell Philippa Marsh?'];
        yield 'the nights follow you home' => [3, 'scarred', 'You leave Kingsport but not the dream. What do you still hear?'];
    }

    #[Test]
    public function aMysteryLeftOpenKeepsItsThread(): void
    {
        $campaign = $this->atTheConfrontation();
        $this->throughTheConfrontation($campaign);
        $campaign->endFlowScene(3, $this->release, self::at());

        $campaign->completeFlowStep('thread', StepResult::pick(ItemKind::Thread, [new PickedItem($this->mysteryThread($campaign)->id()->toString(), 'The mystery')]), $this->release, self::at());
        $campaign->completeFlowStep('solved', StepResult::choice('no'), $this->release, self::at());

        self::assertTrue($this->mysteryThread($campaign)->isOpen());
        self::assertSame('calm', self::position($campaign)[1], 'The tally decides the ending as before.');
    }

    #[Test]
    public function skippingTheThreadPickGoesToTheTallyAndLeavesTheMysteryOpen(): void
    {
        $campaign = $this->atTheConfrontation();
        $this->throughTheConfrontation($campaign);
        $campaign->endFlowScene(3, $this->release, self::at());

        $campaign->skipFlowStep('thread', $this->release, self::at());

        self::assertSame(['setup', 'calm'], self::position($campaign));
        self::assertTrue($this->mysteryThread($campaign)->isOpen());
    }

    #[Test]
    public function withoutAMysteryThreadToPickTheEpilogueGoesToTheTally(): void
    {
        $campaign = $this->atTheConfrontation();
        $campaign->closeThread($this->mysteryThread($campaign)->id());
        $this->throughTheConfrontation($campaign);
        $campaign->endFlowScene(3, $this->release, self::at());

        $campaign->completeFlowStep('thread', StepResult::pick(ItemKind::Thread, []), $this->release, self::at());

        self::assertSame(['setup', 'calm'], self::position($campaign));
    }

    #[Test]
    public function theLairIsMandatoryAndThePlaceAndTheLairFeedTheClosingPrompts(): void
    {
        $campaign = $this->atTheConfrontation();
        try {
            $campaign->skipFlowStep('arrive', $this->release, self::at());
            self::fail('"arrive" is mandatory.');
        } catch (StepCannotBeSkipped $exception) {
            self::assertSame('Step "arrive" is mandatory: complete it.', $exception->getMessage());
        }
        self::assertSame('The trail began at The old customs house. Where does it end? Describe the place and the first thing you notice.', $campaign->flowRunView($this->release)?->step?->prompt);

        $campaign->completeFlowStep('arrive', self::prompt('A cellar under the town hall'), $this->release, self::at());
        $campaign->skipFlowStep('horror', $this->release, self::at());
        $campaign->completeFlowStep('will', $this->checked($campaign, 'will', 'pow', 25), $this->release, self::at());

        self::assertSame('Say how you end it, and what you leave behind in A cellar under the town hall.', $campaign->flowRunView($this->release)?->step?->prompt);
    }

    #[Test]
    #[DataProvider('endings')]
    public function theEpilogueDependsOnTheDreadAndTheFlowRunCompletes(int $dread, string $step, string $prompt): void
    {
        $campaign = $this->atTheConfrontation();
        $campaign->setTrackerValue($this->tracker('dread'), $dread, self::at());
        $this->throughTheConfrontation($campaign);
        $campaign->endFlowScene(3, $this->release, self::at());

        self::assertSame(['epilogue', 'setup', 'thread'], [$campaign->currentScene()?->sceneType(), ...self::position($campaign)]);
        $campaign->completeFlowStep('thread', StepResult::pick(ItemKind::Thread, [new PickedItem($this->mysteryThread($campaign)->id()->toString(), 'The mystery')]), $this->release, self::at());
        $campaign->completeFlowStep('solved', StepResult::choice('yes'), $this->release, self::at());

        self::assertFalse($this->mysteryThread($campaign)->isOpen(), 'Solving the mystery closes its Thread.');
        self::assertSame(['setup', $step, $prompt], [...self::position($campaign), $campaign->flowRunView($this->release)?->step?->prompt]);
        $campaign->skipFlowStep($step, $this->release, self::at());
        $campaign->endFlowScene(4, $this->release, self::at());

        self::assertSame(FlowRunStatus::Completed, self::flowRunOf($campaign)->status());
    }

    private function throughTheConfrontation(Campaign $campaign): void
    {
        $campaign->completeFlowStep('arrive', self::prompt('A cellar under the town hall'), $this->release, self::at());
        foreach (['horror', 'will', 'cost', 'final-shock'] as $key) {
            $campaign->skipFlowStep($key, $this->release, self::at());
        }
    }

    private function mysteryThread(Campaign $campaign): Thread
    {
        return $campaign->threads()[0] ?? self::fail('The mystery has no Thread.');
    }

    /**
     * The investigator at the Sanity check step, with 12 Hit points and the Power set on the test.
     */
    private function atTheSanityStep(string $step): Campaign
    {
        if ('shock' === $step) {
            $campaign = $this->inTheSite();
            $campaign->completeFlowStep('spot', $this->checked($campaign, 'spot', 'spot-hidden', 25), $this->release, self::at());
            $campaign->completeFlowStep('find', self::prompt('A tide table with one night circled'), $this->release, self::at());
            $campaign->completeFlowStep('reveal', self::prompt('Something waits below the tide line'), $this->release, self::at());
        } elseif ('nerve' === $step) {
            $campaign = $this->inTheInvestigation();
            $campaign->pickSceneType('night', $this->release, self::at());
            $campaign->completeFlowStep('event', self::prompt('Bells ring under the water'), $this->release, self::at());
            $campaign->skipFlowStep('hide', $this->release, self::at());
            $campaign->completeFlowStep('glimpse', self::prompt('A pale hand on the glass'), $this->release, self::at());
        } else {
            $campaign = $this->atTheConfrontation();
            $campaign->completeFlowStep('arrive', self::prompt('A cellar under the town hall'), $this->release, self::at());
            foreach (['horror', 'will', 'cost'] as $key) {
                $campaign->skipFlowStep($key, $this->release, self::at());
            }
        }

        self::assertSame($step, self::position($campaign)[1]);

        return $campaign;
    }

    /**
     * The current value of the investigator's track.
     */
    private function current(Campaign $campaign, string $field): mixed
    {
        $value = $campaign->character(CharacterId::fromString('inv'))?->values()[$field] ?? null;

        return \is_array($value) ? $value['current'] : $value;
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

    private function createInvestigator(Campaign $campaign): void
    {
        // Spent by points; a Power under the creation floor (15) and the Hit points, which creation sets,
        // are put in place afterwards, as an edit or the game would.
        $campaign->addCharacter(CharacterId::fromString('inv'), 'Inv', [], [], $this->points(['occupation' => 'Reporter', 'library-use' => 50, 'persuade' => 50, 'psychology' => 50, 'spot-hidden' => 50, 'stealth' => 50]), $this->release, CreationMethod::Points);
        $campaign->changeCharacterValue(CharacterId::fromString('inv'), 'pow', $this->pow);
        $campaign->changeCharacterValue(CharacterId::fromString('inv'), 'hp', ['current' => $this->hp, 'max' => $this->hp]);
        $campaign->completeFlowStep('you', StepResult::character('inv'), $this->release, self::at(), new ScriptedRandomNumberGenerator(4, 4, 4));
    }

    /**
     * The eight characteristics at 50 (400 of the 460 points), with the given ones replaced and the
     * given other values added.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function points(array $overrides = []): array
    {
        return [...array_fill_keys(['str', 'con', 'siz', 'dex', 'app', 'int', 'pow', 'edu'], 50), ...$overrides];
    }

    /**
     * A campaign past the Setup scene, at the investigation phase's scene pick.
     */
    private function inTheInvestigation(): Campaign
    {
        $campaign = self::guided($this->release, 'kingsport-mystery');
        $campaign->startSession(self::at(), $this->release);
        $this->createInvestigator($campaign);
        $campaign->completeFlowStep('contact', self::prompt('Philippa Marsh'), $this->release, self::at());
        $campaign->completeFlowStep('hook', self::prompt('My aunt writes that the house is not safe'), $this->release, self::at());
        $campaign->completeFlowStep('where', self::prompt('The old customs house'), $this->release, self::at());
        $campaign->completeFlowStep('mystery', self::prompt('Who emptied my aunt\'s cellar?'), $this->release, self::at());
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
