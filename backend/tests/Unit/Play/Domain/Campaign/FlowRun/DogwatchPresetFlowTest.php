<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\PickedItem;
use App\Play\Domain\Campaign\FlowRun\StepCannotBeSkipped;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\GameSystem\Check\Check;
use App\Play\Domain\GameSystem\Check\CheckBand;
use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\Sheet\Field;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shipped `dogwatch` preset played through the Campaign: the eight Station checks and their
 * bands, Session Zero (crew, compartments as Places, the manifest as NPCs), the Wake, the Ship loop
 * with its compartment pick and its Threat-driven close, the Reveal that rolls the three Truths,
 * and the four endings. Ada and Bo are the crew.
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(Campaign::class)]
final class DogwatchPresetFlowTest extends FlowRunTestCase
{
    private GameSystemSnapshot $release;

    protected function setUp(): void
    {
        /** @var array<string, mixed> $content */
        $content = json_decode((string) file_get_contents(__DIR__.'/../../../../../../presets/dogwatch.json'), true, flags: \JSON_THROW_ON_ERROR);
        $this->release = self::translate($content);
    }

    #[Test]
    public function thereAreEightChecksOneDiePerSizePlainAndHelped(): void
    {
        $dice = [];
        foreach ($this->release->checks() as $check) {
            $dice[$check->key] = $check->dice?->notation();
            self::assertSame([3, 2, 8], [$check->difficulty?->default, $check->difficulty?->min, $check->difficulty?->max], $check->key);
            self::assertSame(['wrong', 'short', 'done'], array_map(static fn (CheckBand $band): string => $band->key, $check->bands), $check->key);
        }

        ksort($dice);
        self::assertSame(['d10' => '1d10', 'd10-help' => '2d10kh1', 'd4' => '1d4', 'd4-help' => '2d4kh1', 'd6' => '1d6', 'd6-help' => '2d6kh1', 'd8' => '1d8', 'd8-help' => '2d8kh1'], $dice);
    }

    /**
     * @return iterable<string, array{string, list<int>, ?int, string}> the check, its scripted dice, the Difficulty set and the band
     */
    public static function bands(): iterable
    {
        yield 'a 1 goes wrong' => ['d8', [1], null, 'wrong'];
        yield 'under the default Difficulty falls short' => ['d8', [2], null, 'short'];
        yield 'the Difficulty itself is done' => ['d8', [3], null, 'done'];
        yield 'over it is done' => ['d10', [10], null, 'done'];
        yield 'a 1 goes wrong at Difficulty 2' => ['d6', [1], 2, 'wrong'];
        yield 'a 2 is done at Difficulty 2' => ['d6', [2], 2, 'done'];
        yield 'a 5 falls short at Difficulty 6' => ['d6', [5], 6, 'short'];
        yield 'the helped die keeps the highest' => ['d6-help', [1, 4], 4, 'done'];
        yield 'the helped die can still go wrong' => ['d4-help', [1, 1], null, 'wrong'];
    }

    /**
     * @param list<int> $dice
     */
    #[Test]
    #[DataProvider('bands')]
    public function aRollFallsInTheBandOfItsDifficulty(string $key, array $dice, ?int $difficulty, string $band): void
    {
        $check = $this->release->check($key) ?? self::fail('No check.');
        $result = $check->perform(new ScriptedRandomNumberGenerator(...$dice), SheetValues::of($this->release->sheet(), [], []), $difficulty, 0);

        self::assertSame($band, $result->band->key);
    }

    #[Test]
    public function theSheetHasFourStationsNerveInjuriesAndSixSlotsOfKit(): void
    {
        $sheet = $this->release->sheet();

        self::assertSame(['role', 'helm', 'hands', 'eyes', 'voice', 'nerve', 'injuries', 'chits', 'kit'], array_map(static fn (Field $field): string => $field->key, $sheet->fields()));
        self::assertSame('chits', $sheet->currency()?->field);
        self::assertCount(11, $this->release->gear());
        self::assertSame(['threat', 'clues'], array_map(static fn (\App\Play\Domain\GameSystem\Tracker $tracker): string => $tracker->key, $this->release->trackers()));
        self::assertSame(['aboard', 'who', 'why'], array_map(static fn (\App\Play\Domain\GameSystem\FactSlot $slot): string => $slot->key, $this->release->factSlots()));
    }

    #[Test]
    public function everyCheckStepOffersTheWholeSetAndTheThreeTruthTablesHaveEightAnswers(): void
    {
        $steps = 0;
        foreach ($this->release->sceneTypes() as $sceneType) {
            foreach ([$sceneType->setup, $sceneType->play, $sceneType->closing] as $part) {
                foreach ($part->steps as $step) {
                    if ($step instanceof \App\Play\Domain\GameSystem\Flow\CheckStep) {
                        ++$steps;
                        self::assertCount(8, $step->checks, $step->key);
                    }
                }
            }
        }

        self::assertSame(5, $steps);
        foreach (['truth-aboard', 'truth-who', 'truth-why'] as $table) {
            $texts = [];
            for ($roll = 1; $roll <= 8; ++$roll) {
                $texts[] = $this->release->resolveOracleTable($table, new ScriptedRandomNumberGenerator($roll))->steps()[0]->text();
            }

            self::assertCount(8, array_unique($texts), $table);
        }
    }

    #[Test]
    public function sessionZeroMakesTheCrewTheCompartmentsAndTheManifest(): void
    {
        $campaign = $this->sessionZeroDone();

        self::assertSame(
            [['Cold Hold', 'Drive Spine', 'Wake Bay'], [['compartment']], ['Juno Kestrel', 'Pell'], [['crew'], ['crew']]],
            [
                $this->names($campaign->places()),
                array_values(array_unique(array_map(static fn (\App\Play\Domain\Campaign\Lists\Place $place): array => $place->tags(), $campaign->places()), \SORT_REGULAR)),
                $this->names($campaign->npcs()),
                array_map(static fn (\App\Play\Domain\Campaign\Lists\Npc $npc): array => $npc->tags(), $campaign->npcs()),
            ],
        );
        self::assertSame(1, self::flowRunOf($campaign)->phaseIndex());
        self::assertSame(['ada', 'bo'], array_map(static fn (CharacterId $id): string => $id->toString(), $campaign->currentSession()?->party() ?? []));
        self::assertSame(6, $this->nerve($campaign, 'ada'), 'Nerve is a track with a maximum, so a new crew member starts full.');
    }

    #[Test]
    public function theWakeNamesTheMissingOneAndEndsInTheShipLoop(): void
    {
        $campaign = $this->wakeDone();

        self::assertSame(2, self::flowRunOf($campaign)->phaseIndex());
        self::assertSame(['Where is Tam Ordo?'], array_map(static fn (\App\Play\Domain\Campaign\Lists\Thread $thread): string => $thread->name(), $campaign->threads()));
    }

    #[Test]
    public function aPushedCustodianRaisesThreat(): void
    {
        $campaign = $this->sessionZeroDone();
        $campaign->pickSceneType('empty-berth', $this->release, self::at());
        $campaign->completeFlowStep('berth', self::prompt('Tam Ordo'), $this->release, self::at());
        $this->endScene($campaign, ['left']);
        $campaign->skipFlowStep('first', $this->release, self::at());
        $campaign->pickSceneType('custodian', $this->release, self::at());
        $campaign->completeFlowStep('ask', self::prompt('Why am I awake?'), $this->release, self::at());
        $campaign->skipFlowStep('rule', $this->release, self::at());
        $campaign->completeFlowStep('push', StepResult::choice('push'), $this->release, self::at());

        self::assertSame(1, $this->tracker($campaign, 'threat'));
    }

    #[Test]
    public function theShipOpensEveryJobByPickingACompartment(): void
    {
        $campaign = $this->wakeDone();
        $campaign->pickSceneType('repair', $this->release, self::at());
        self::assertSame('where', self::flowRunOf($campaign)->stepKey());

        $campaign->completeFlowStep('where', StepResult::pick(ItemKind::Place, [$this->compartment($campaign)]), $this->release, self::at());

        self::assertSame('here', self::flowRunOf($campaign)->stepKey(), 'A compartment was picked: the scene asks what is wrong in it.');
    }

    #[Test]
    public function withNoCompartmentLeftTheShipAsksForANewOne(): void
    {
        $campaign = $this->wakeDone();
        foreach ($campaign->places() as $place) {
            $campaign->closePlace($place->id());
        }

        $campaign->pickSceneType('hide', $this->release, self::at());

        $campaign->completeFlowStep('where', StepResult::pick(ItemKind::Place, []), $this->release, self::at());
        self::assertSame('improvise', self::flowRunOf($campaign)->stepKey());
        $campaign->completeFlowStep('improvise', self::prompt('The laundry'), $this->release, self::at());

        self::assertContains('The laundry', array_map(static fn (\App\Play\Domain\Campaign\Lists\Place $place): string => $place->name(), $campaign->places()));
        self::assertSame('quiet', self::flowRunOf($campaign)->stepKey());
    }

    /**
     * @return iterable<string, array{string, string, string, int, int, int}> the job, its check step, the band, the Threat after, the Clues and the Nerve
     */
    public static function jobs(): iterable
    {
        yield 'a search that is done adds a Clue' => ['investigate', 'look', 'done', 3, 1, 6];
        yield 'a search that falls short raises Threat' => ['investigate', 'look', 'short', 4, 0, 6];
        yield 'a search that goes wrong raises Threat and costs Nerve' => ['investigate', 'look', 'wrong', 4, 0, 5];
        yield 'a repair that is done lowers Threat' => ['repair', 'fix', 'done', 2, 0, 6];
        yield 'a repair that falls short changes nothing' => ['repair', 'fix', 'short', 3, 0, 6];
        yield 'a repair that goes wrong raises Threat and costs Nerve' => ['repair', 'fix', 'wrong', 4, 0, 5];
        yield 'a confrontation that is done adds a Clue' => ['confront', 'face', 'done', 3, 1, 6];
        yield 'a confrontation that falls short costs Nerve' => ['confront', 'face', 'short', 3, 0, 5];
        yield 'a confrontation that goes wrong costs two Nerve and raises Threat' => ['confront', 'face', 'wrong', 4, 0, 4];
        yield 'a good hide gives back a breath' => ['hide', 'quiet', 'done', 3, 0, 6];
        yield 'a hide that falls short raises Threat' => ['hide', 'quiet', 'short', 4, 0, 6];
        yield 'a hide that goes wrong raises Threat and costs Nerve' => ['hide', 'quiet', 'wrong', 4, 0, 5];
    }

    #[Test]
    #[DataProvider('jobs')]
    public function eachJobsCheckChangesThreatCluesAndNerveByItsBand(string $job, string $step, string $band, int $threat, int $clues, int $nerve): void
    {
        $campaign = $this->wakeDone();
        $this->setTracker($campaign, 'threat', 3);
        $campaign->pickSceneType($job, $this->release, self::at());
        $campaign->completeFlowStep('where', StepResult::pick(ItemKind::Place, [$this->compartment($campaign)]), $this->release, self::at());
        $campaign->completeFlowStep('here', self::prompt('A scorch mark'), $this->release, self::at());
        $campaign->completeFlowStep($step, $this->performed($band), $this->release, self::at());

        self::assertSame([$threat, $clues, $nerve], [$this->tracker($campaign, 'threat'), $this->tracker($campaign, 'clues'), $this->nerve($campaign, 'ada')]);
    }

    #[Test]
    public function aHideThatIsDoneNeverRaisesNervePastItsMaximum(): void
    {
        $campaign = $this->wakeDone();
        $campaign->pickSceneType('hide', $this->release, self::at());
        $campaign->completeFlowStep('where', StepResult::pick(ItemKind::Place, [$this->compartment($campaign)]), $this->release, self::at());
        $campaign->completeFlowStep('here', self::prompt('Behind the lockers'), $this->release, self::at());
        $campaign->completeFlowStep('quiet', $this->performed('done'), $this->release, self::at());
        self::assertSame(6, $this->nerve($campaign, 'ada'), 'Nerve was full: a good hide cannot raise it past 6.');
    }

    #[Test]
    public function aHideThatIsDoneGivesBackOneNerveBelowTheMaximum(): void
    {
        $campaign = $this->wakeDone();
        $this->setNerve($campaign, 'ada', 3);
        $campaign->pickSceneType('hide', $this->release, self::at());
        $campaign->completeFlowStep('where', StepResult::pick(ItemKind::Place, [$this->compartment($campaign)]), $this->release, self::at());
        $campaign->completeFlowStep('here', self::prompt('Behind the lockers'), $this->release, self::at());
        $campaign->completeFlowStep('quiet', $this->performed('done'), $this->release, self::at());

        self::assertSame(4, $this->nerve($campaign, 'ada'));
    }

    /**
     * @return iterable<string, array{string, string, string, int, int}> the job, its check step, the band, the Nerve before and after
     */
    public static function harmBelowTheFloor(): iterable
    {
        yield 'harm at Nerve 0 leaves it at 0' => ['confront', 'face', 'short', 0, 0];
        yield 'a 2-point loss at Nerve 0 leaves it at 0' => ['confront', 'face', 'wrong', 0, 0];
        yield 'a 2-point loss at Nerve 1 stops at 0' => ['confront', 'face', 'wrong', 1, 0];
        yield 'a 1-point loss at Nerve 1 reaches 0' => ['repair', 'fix', 'wrong', 1, 0];
    }

    #[Test]
    #[DataProvider('harmBelowTheFloor')]
    public function nerveNeverGoesBelowZero(string $job, string $step, string $band, int $before, int $after): void
    {
        $campaign = $this->wakeDone();
        $this->setNerve($campaign, 'ada', $before);
        $campaign->pickSceneType($job, $this->release, self::at());
        $campaign->completeFlowStep('where', StepResult::pick(ItemKind::Place, [$this->compartment($campaign)]), $this->release, self::at());
        $campaign->completeFlowStep('here', self::prompt('A scorch mark'), $this->release, self::at());
        $campaign->completeFlowStep($step, $this->performed($band), $this->release, self::at());

        self::assertSame($after, $this->nerve($campaign, 'ada'), 'The player marks the Injury by hand: no effect changes the Injuries.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function jobKeys(): iterable
    {
        yield 'Investigate' => ['investigate'];
        yield 'Repair' => ['repair'];
        yield 'Confront' => ['confront'];
        yield 'Hide' => ['hide'];
    }

    #[Test]
    #[DataProvider('jobKeys')]
    public function everyJobMustPickItsCompartmentButAnEmptyPickStillCompletes(string $job): void
    {
        $campaign = $this->wakeDone();
        $campaign->pickSceneType($job, $this->release, self::at());

        try {
            $campaign->skipFlowStep('where', $this->release, self::at());
            self::fail('The compartment pick can be skipped.');
        } catch (StepCannotBeSkipped) {
            self::assertSame('where', self::flowRunOf($campaign)->stepKey());
        }

        foreach ($campaign->places() as $place) {
            $campaign->closePlace($place->id());
        }

        $campaign->completeFlowStep('where', StepResult::pick(ItemKind::Place, []), $this->release, self::at());
        self::assertSame('improvise', self::flowRunOf($campaign)->stepKey());
    }

    #[Test]
    public function theMissingPickCannotBeSkipped(): void
    {
        $campaign = $this->afterScene();
        $campaign->completeFlowStep('watch', self::prompt('The light goes amber'), $this->release, self::at());
        $campaign->completeFlowStep('trouble', StepResult::table($this->release->resolveOracleTable('ship-trouble', new ScriptedRandomNumberGenerator(1))), $this->release, self::at());

        $this->expectException(StepCannotBeSkipped::class);
        $campaign->skipFlowStep('missing', $this->release, self::at());
    }

    #[Test]
    public function aClosedCrewMemberIsNeverTakenAgainAndWithNoCrewLeftTheTurnEnds(): void
    {
        $campaign = $this->afterScene();
        $crew = array_map(static fn (\App\Play\Domain\Campaign\Lists\Npc $npc): string => $npc->name(), $campaign->npcs());
        $taken = [];
        foreach ($crew as $turn => $_) {
            if ($turn > 0) {
                $this->playInvestigateScene($campaign);
            }

            $campaign->completeFlowStep('watch', self::prompt('The light goes amber'), $this->release, self::at());
            $campaign->completeFlowStep('trouble', StepResult::table($this->release->resolveOracleTable('ship-trouble', new ScriptedRandomNumberGenerator(1))), $this->release, self::at());

            $candidates = $campaign->pickCandidates(ItemKind::Npc, ['crew'])->items;
            self::assertCount(\count($crew) - $turn, $candidates, 'A closed crew member is not offered.');
            self::assertSame([], array_intersect($taken, array_map(static fn (\App\Play\Domain\Campaign\FlowRun\PickCandidate $item): string => $item->name, $candidates)));
            $campaign->completeFlowStep('missing', StepResult::pick(ItemKind::Npc, [new PickedItem($candidates[0]->id, $candidates[0]->name)]), $this->release, self::at());
            $campaign->completeFlowStep('gone', self::prompt('Last seen at the hatch'), $this->release, self::at());
            $campaign->closeNpc(NpcId::fromString($candidates[0]->id));
            $taken[] = $candidates[0]->name;
        }

        $this->playInvestigateScene($campaign);
        $campaign->completeFlowStep('watch', self::prompt('The light goes amber'), $this->release, self::at());
        $campaign->completeFlowStep('trouble', StepResult::table($this->release->resolveOracleTable('ship-trouble', new ScriptedRandomNumberGenerator(1))), $this->release, self::at());

        self::assertSame([], $campaign->pickCandidates(ItemKind::Npc, ['crew'])->items);
        $threads = \count($campaign->threads());
        $campaign->completeFlowStep('missing', StepResult::pick(ItemKind::Npc, []), $this->release, self::at());
        self::assertNotSame('gone', self::flowRunOf($campaign)->stepKey());
        self::assertCount($threads, $campaign->threads());
    }

    #[Test]
    public function theTreasonTableNamesASuspectAndAddsAClue(): void
    {
        $campaign = $this->wakeDone();
        $campaign->pickSceneType('investigate', $this->release, self::at());
        $campaign->completeFlowStep('where', StepResult::pick(ItemKind::Place, [$this->compartment($campaign)]), $this->release, self::at());
        $campaign->completeFlowStep('here', self::prompt('A scorch mark'), $this->release, self::at());
        $campaign->skipFlowStep('look', $this->release, self::at());

        $campaign->completeFlowStep('doubt', StepResult::table($this->release->resolveOracleTable('treason', new ScriptedRandomNumberGenerator(1))), $this->release, self::at());

        self::assertSame(1, $this->tracker($campaign, 'clues'));
        self::assertContains('Who falsified the cryo log?', array_map(static fn (\App\Play\Domain\Campaign\Lists\Thread $thread): string => $thread->name(), $campaign->threads()));
    }

    /**
     * @return iterable<string, array{int, ?string}> the Ship trouble roll and the scene it forces
     */
    public static function trouble(): iterable
    {
        yield 'a quiet hour forces nothing' => [2, null];
        yield 'a hatch raises Threat and forces nothing' => [4, null];
        yield 'a failing system forces a Repair' => [6, 'repair'];
        yield 'a noise in the walls forces a Hide' => [7, 'hide'];
        yield 'a voice forces an Investigate' => [8, 'investigate'];
        yield 'a figure forces a Confront' => [9, 'confront'];
    }

    #[Test]
    #[DataProvider('trouble')]
    public function theWorldTurnRaisesThreatAndTheShipMakesTrouble(int $roll, ?string $forced): void
    {
        $campaign = $this->afterScene();
        $campaign->completeFlowStep('watch', self::prompt('The light goes amber'), $this->release, self::at());
        self::assertSame(1, $this->tracker($campaign, 'threat'));

        $campaign->completeFlowStep('trouble', StepResult::table($this->release->resolveOracleTable('ship-trouble', new ScriptedRandomNumberGenerator($roll))), $this->release, self::at());

        self::assertSame($forced, self::flowRunOf($campaign)->forcedNextSceneType());
    }

    #[Test]
    public function aMissingCrewMemberBecomesAThread(): void
    {
        $campaign = $this->afterScene();
        $campaign->completeFlowStep('watch', self::prompt('The light goes amber'), $this->release, self::at());
        $campaign->completeFlowStep('trouble', StepResult::table($this->release->resolveOracleTable('ship-trouble', new ScriptedRandomNumberGenerator(1))), $this->release, self::at());
        $npc = $campaign->npcs()[0];

        $campaign->completeFlowStep('missing', StepResult::pick(ItemKind::Npc, [new PickedItem($npc->id()->toString(), $npc->name())]), $this->release, self::at());
        self::assertSame('gone', self::flowRunOf($campaign)->stepKey());
        $campaign->completeFlowStep('gone', self::prompt('Last seen at the hatch'), $this->release, self::at());

        self::assertContains(\sprintf('Where is %s?', $npc->name()), array_map(static fn (\App\Play\Domain\Campaign\Lists\Thread $thread): string => $thread->name(), $campaign->threads()));
    }

    #[Test]
    public function withNobodyLeftToTakeTheWorldTurnEndsWithoutAThread(): void
    {
        $campaign = $this->afterScene();
        $campaign->completeFlowStep('watch', self::prompt('The light goes amber'), $this->release, self::at());
        $campaign->completeFlowStep('trouble', StepResult::table($this->release->resolveOracleTable('ship-trouble', new ScriptedRandomNumberGenerator(1))), $this->release, self::at());
        foreach ($campaign->npcs() as $npc) {
            $campaign->closeNpc($npc->id());
        }

        $threads = \count($campaign->threads());

        $campaign->completeFlowStep('missing', StepResult::pick(ItemKind::Npc, []), $this->release, self::at());

        self::assertNotSame('gone', self::flowRunOf($campaign)->stepKey());
        self::assertCount($threads, $campaign->threads());
    }

    /**
     * @return iterable<string, array{int, ?int, int}> Threat before the scene ends, the Reveal's d6 and the index of the phase after the scene
     */
    public static function theRevealComesWithThreat(): iterable
    {
        yield 'Threat 4 is quiet and nothing is rolled' => [4, null, 2];
        yield 'Threat 5 is rising and the Reveal stays away on a 4' => [5, 4, 2];
        yield 'Threat 5 is rising and the Reveal comes on a 5' => [5, 5, 3];
        yield 'Threat 7 is still rising and the Reveal comes on a 6' => [7, 6, 3];
        yield 'Threat 7 is still rising and the Reveal stays away on a 1' => [7, 1, 2];
        yield 'a full Threat brings the Reveal without a roll' => [8, null, 3];
    }

    #[Test]
    #[DataProvider('theRevealComesWithThreat')]
    public function theShipLoopEndsOnThreatAndOnlyThen(int $threat, ?int $die, int $phase): void
    {
        $campaign = $this->wakeDone();
        $this->setTracker($campaign, 'threat', $threat);
        $this->playInvestigateScene($campaign);
        if (null !== $die) {
            self::assertSame('lights-on', self::flowRunOf($campaign)->stepKey());
            $campaign->completeFlowStep('lights-on', self::roll('1d6', $die), $this->release, self::at());
        }

        self::assertSame($phase, self::flowRunOf($campaign)->phaseIndex());
    }

    #[Test]
    public function farFromTheRevealTheShipLoopLeavesNoPendingRollForIt(): void
    {
        $campaign = $this->wakeDone();
        $this->setTracker($campaign, 'threat', 4);
        $this->playInvestigateScene($campaign);

        self::assertSame('watch', self::flowRunOf($campaign)->stepKey());
    }

    #[Test]
    public function theRevealRollsTheThreeTruthsIntoTheFactSlots(): void
    {
        $campaign = $this->wakeDone();
        $this->setTracker($campaign, 'threat', 8);
        $this->playInvestigateScene($campaign);
        self::assertSame(3, self::flowRunOf($campaign)->phaseIndex());

        $campaign->completeFlowStep('aboard-roll', StepResult::table($this->release->resolveOracleTable('truth-aboard', new ScriptedRandomNumberGenerator(2))), $this->release, self::at());
        $campaign->completeFlowStep('who-roll', StepResult::table($this->release->resolveOracleTable('truth-who', new ScriptedRandomNumberGenerator(3))), $this->release, self::at());
        $campaign->completeFlowStep('why-roll', StepResult::table($this->release->resolveOracleTable('truth-why', new ScriptedRandomNumberGenerator(8))), $this->release, self::at());

        self::assertSame(
            ['aboard' => 'Seed stock for a settlement nobody voted for', 'who' => 'Nobody. The ship took it on at a dock, alone', 'why' => 'The wake order was a mistake, and no one has told the ship'],
            array_intersect_key($campaign->factValues($this->release), ['aboard' => 1, 'who' => 1, 'why' => 1]),
        );
        self::assertSame('read', self::flowRunOf($campaign)->stepKey());
    }

    /**
     * @return iterable<string, array{int, int, string}> Threat, Clues and the ending reached
     */
    public static function endings(): iterable
    {
        yield 'contained and understood' => [5, 3, 'ending-clear'];
        yield 'contained and blind' => [2, 2, 'ending-scraped'];
        yield 'boiling over and understood' => [6, 3, 'ending-price'];
        yield 'boiling over and blind' => [8, 0, 'ending-lost'];
    }

    #[Test]
    #[DataProvider('endings')]
    public function theEpilogueReadsThreatAndClues(int $threat, int $clues, string $ending): void
    {
        $campaign = $this->wakeDone();
        $this->setTracker($campaign, 'threat', 8);
        $this->playInvestigateScene($campaign);
        foreach (['aboard-roll' => 'truth-aboard', 'who-roll' => 'truth-who', 'why-roll' => 'truth-why'] as $step => $table) {
            $campaign->completeFlowStep($step, StepResult::table($this->release->resolveOracleTable($table, new ScriptedRandomNumberGenerator(1))), $this->release, self::at());
        }

        $campaign->completeFlowStep('read', self::prompt('One story'), $this->release, self::at());
        $this->endScene($campaign, ['hold']);
        // The Epilogue starts by itself when the Reveal's closing is done, and reads the Trackers as they stand.
        $this->setTracker($campaign, 'threat', $threat);
        $this->setTracker($campaign, 'clues', $clues);
        $campaign->skipFlowStep('choose', $this->release, self::at());

        self::assertSame($ending, self::flowRunOf($campaign)->stepKey());
        $campaign->completeFlowStep($ending, self::prompt('It ends'), $this->release, self::at());
        self::assertNull(self::flowRunOf($campaign)->stepKey(), 'The ending does not fall into the next one.');
    }

    /**
     * Ada and Bo make the crew, the ship and the manifest, and set down their hunches; the FlowRun waits at the Wake.
     */
    private function sessionZeroDone(): Campaign
    {
        $campaign = self::guided($this->release, 'cold-berth');
        $campaign->startSession(self::at(), $this->release);
        // Dogwatch rolls nothing: the Stations are chosen, so the crew are made from given values.
        foreach (['ada' => ['d10', 'd8', 'd6', 'd4'], 'bo' => ['d4', 'd6', 'd8', 'd10']] as $id => [$helm, $hands, $eyes, $voice]) {
            $campaign->addCharacter(CharacterId::fromString($id), ucfirst($id), [], [], ['helm' => $helm, 'hands' => $hands, 'eyes' => $eyes, 'voice' => $voice], $this->release);
        }

        $campaign->pickSceneType('crew', $this->release, self::at());
        $campaign->completeFlowStep('who', self::prompt('Shift partners'), $this->release, self::at());
        foreach (['ada' => 1, 'bo' => 2] as $id => $n) {
            $campaign->completeFlowStep('crew-'.$n, StepResult::character($id), $this->release, self::at());
            $campaign->completeFlowStep('outfit-'.$n, StepResult::shop($id, [], []), $this->release, self::at());
        }

        $campaign->skipFlowStep('crew-3', $this->release, self::at());
        $campaign->completeFlowStep('outfit-3', StepResult::shop('ada', [], []), $this->release, self::at());
        $this->endScene($campaign);

        $campaign->pickSceneType('ship', $this->release, self::at());
        foreach (['kind' => 'A long hauler, asleep eleven years', 'wake' => 'Wake Bay', 'engine' => 'Drive Spine', 'hold' => 'Cold Hold'] as $key => $answer) {
            $campaign->completeFlowStep($key, self::prompt($answer), $this->release, self::at());
        }

        $campaign->skipFlowStep('sealed', $this->release, self::at());
        $campaign->completeFlowStep('manifest-1', self::prompt('Juno Kestrel'), $this->release, self::at());
        $campaign->completeFlowStep('manifest-2', self::prompt('Pell'), $this->release, self::at());
        $campaign->skipFlowStep('manifest-3', $this->release, self::at());
        $this->endScene($campaign);

        $campaign->pickSceneType('truths', $this->release, self::at());
        $this->endScene($campaign, ['aboard', 'who', 'why']);

        return $campaign;
    }

    /**
     * The empty berth and the custodian played: the FlowRun waits at the Ship loop's job pick.
     */
    private function wakeDone(): Campaign
    {
        $campaign = $this->sessionZeroDone();
        $campaign->pickSceneType('empty-berth', $this->release, self::at());
        $campaign->completeFlowStep('berth', self::prompt('Tam Ordo'), $this->release, self::at());
        $this->endScene($campaign, ['left']);
        $campaign->skipFlowStep('first', $this->release, self::at());
        $campaign->pickSceneType('custodian', $this->release, self::at());
        $campaign->completeFlowStep('ask', self::prompt('Why am I awake?'), $this->release, self::at());
        $this->endScene($campaign, ['rule', 'push']);

        return $campaign;
    }

    /**
     * A Hide scene played and ended on Threat 0, waiting at the world turn's first step.
     */
    private function afterScene(): Campaign
    {
        $campaign = $this->wakeDone();
        $this->playInvestigateScene($campaign);

        return $campaign;
    }

    /**
     * An Investigate scene played with its check skipped, down to the end of its closing.
     */
    private function playInvestigateScene(Campaign $campaign): void
    {
        $campaign->pickSceneType('investigate', $this->release, self::at());
        $campaign->completeFlowStep('where', StepResult::pick(ItemKind::Place, [$this->compartment($campaign)]), $this->release, self::at());
        $campaign->completeFlowStep('here', self::prompt('A scorch mark'), $this->release, self::at());
        $this->endScene($campaign, ['look', 'doubt']);
        $campaign->skipFlowStep('after', $this->release, self::at());
    }

    /**
     * @param list<string> $skipping
     */
    private function endScene(Campaign $campaign, array $skipping = []): void
    {
        foreach ($skipping as $key) {
            $campaign->skipFlowStep($key, $this->release, self::at());
        }

        $campaign->endFlowScene($campaign->currentScene()?->number() ?? self::fail('No scene to end.'), $this->release, self::at());
    }

    private function compartment(Campaign $campaign): PickedItem
    {
        $place = $campaign->places()[0];

        return new PickedItem($place->id()->toString(), $place->name());
    }

    /**
     * Ada's d8 roll (Difficulty 3) that lands in the band: a 1 goes wrong, a 2 falls short, a 3 is done.
     */
    private function performed(string $band): StepResult
    {
        $roll = ['wrong' => 1, 'short' => 2, 'done' => 3][$band];
        $check = $this->release->check('d8') ?? self::fail('No check.');
        $result = $check->perform(new ScriptedRandomNumberGenerator($roll), SheetValues::of($this->release->sheet(), [], []), null, 0);
        self::assertSame($band, $result->band->key);

        return StepResult::check('ada', $result, 'd8');
    }

    /**
     * @param list<\App\Play\Domain\Campaign\Lists\Place|\App\Play\Domain\Campaign\Lists\Npc> $items
     *
     * @return list<string>
     */
    private function names(array $items): array
    {
        $names = array_map(static fn (\App\Play\Domain\Campaign\Lists\Place|\App\Play\Domain\Campaign\Lists\Npc $item): string => $item->name(), $items);
        sort($names);

        return $names;
    }

    private function tracker(Campaign $campaign, string $key): int
    {
        return $campaign->trackerValue($this->release->tracker($key) ?? self::fail('No tracker.'));
    }

    private function setNerve(Campaign $campaign, string $id, int $current): void
    {
        $campaign->changeCharacterValue(CharacterId::fromString($id), 'nerve', ['current' => $current, 'max' => 6]);
    }

    private function setTracker(Campaign $campaign, string $key, int $value): void
    {
        $campaign->setTrackerValue($this->release->tracker($key) ?? self::fail('No tracker.'), $value, self::at());
    }

    private function nerve(Campaign $campaign, string $id): int
    {
        $nerve = $campaign->character(CharacterId::fromString($id))?->values()['nerve'] ?? self::fail('No Nerve.');
        \assert(\is_array($nerve) && \is_int($nerve['current']));

        return $nerve['current'];
    }
}
