<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\CreationMethod;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Randomness\Domain\Oracle\OracleTableResult;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shipped `hard-country` preset played through the Campaign: the d20 check's four bands, Session
 * Zero, the road deck, a rest and a crossing, the world turn's Winter and Road Risks (a risen ford
 * forces a Ford scene), the gate that comes into sight late, and the three endings. Ada (Back 10,
 * Feet 12, Head 9) and Bo are the bearers.
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(Campaign::class)]
final class HardCountryPresetFlowTest extends FlowRunTestCase
{
    private GameSystemSnapshot $release;

    protected function setUp(): void
    {
        /** @var array<string, mixed> $content */
        $content = json_decode((string) file_get_contents(__DIR__.'/../../../../../../presets/hard-country.json'), true, flags: \JSON_THROW_ON_ERROR);
        $this->release = self::translate($content);
    }

    /**
     * @return iterable<string, array{int, int, string}> the trait, the d20 and the band
     */
    public static function edges(): iterable
    {
        yield 'a 1 is clean' => [10, 1, 'clean'];
        yield 'a 1 is clean even on a low trait' => [5, 1, 'clean'];
        yield 'a 2 is a success' => [10, 2, 'success'];
        yield 'the trait itself is a success' => [10, 10, 'success'];
        yield 'one over the trait fails' => [10, 11, 'failure'];
        yield '19 fails' => [10, 19, 'failure'];
        yield 'a 20 goes badly' => [10, 20, 'badly'];
        yield 'a 20 goes badly on the highest trait' => [18, 20, 'badly'];
    }

    #[Test]
    #[DataProvider('edges')]
    public function aD20FallsInTheBandOfItsEdge(int $trait, int $roll, string $band): void
    {
        $result = $this->release->check('back')?->perform(new ScriptedRandomNumberGenerator($roll), $this->values(['back' => $trait]), null, 0);

        self::assertSame($band, $result?->band->key);
    }

    #[Test]
    public function eachCheckReadsItsOwnTraitAndTheBandsHaveLabels(): void
    {
        $values = $this->values(['back' => 6, 'feet' => 14, 'head' => 9]);

        foreach ([['back', 12, 'failure'], ['feet', 12, 'success'], ['head', 9, 'success'], ['head', 10, 'failure']] as [$key, $roll, $band]) {
            $check = $this->release->check($key) ?? self::fail('No check.');
            self::assertSame($band, $check->perform(new ScriptedRandomNumberGenerator($roll), $values, null, 0)->band->key, $key);
            self::assertSame(['Clean success', 'Success', 'Failure', 'Goes badly'], array_map(static fn (\App\Play\Domain\GameSystem\Check\CheckBand $band): string => $band->label, $check->bands), $key);
        }
    }

    #[Test]
    public function theSheetCarriesEightSlotsOfLoadAndPennies(): void
    {
        $sheet = $this->release->sheet();

        self::assertSame('pennies', $sheet->currency()?->field);
        self::assertSame(['back', 'feet', 'head', 'wind', 'wounds', 'pennies', 'load'], \array_slice(array_map(static fn (\App\Play\Domain\GameSystem\Sheet\Field $field): string => $field->key, $sheet->fields()), 1));
        self::assertCount(13, $this->release->gear());
    }

    #[Test]
    public function sessionZeroMakesTheBearersTheSaintAndTheVow(): void
    {
        $campaign = $this->sessionZeroDone();

        self::assertSame(
            ['A pilgrim of the ford, remembered for her laugh', 'Pay the debt we owe the shrine', 'The Priory of the Narrow Door'],
            [$campaign->factValues($this->release)['saint'], $campaign->factValues($this->release)['vow'], $campaign->places()[0]->name()],
        );
        self::assertSame([1, null, ['ada', 'bo']], [self::flowRunOf($campaign)->phaseIndex(), self::flowRunOf($campaign)->sceneType(), array_map(static fn (CharacterId $id): string => $id->toString(), $campaign->currentSession()?->party() ?? [])]);
        self::assertSame(5, $this->wind($campaign, 'ada'), 'The bearer step sets Wind to d6+2: a 3 on the die.');
    }

    /**
     * @return iterable<string, array{int, string}> the road deck roll and the Scene Type it names
     */
    public static function deck(): iterable
    {
        yield 'the first copies are a Camp' => [1, 'camp'];
        yield 'the fourth copy is a Ford' => [4, 'ford'];
        yield 'then a Stranger' => [6, 'stranger'];
        yield 'then a Ruin' => [9, 'ruin'];
        yield 'the last copies are an Omen' => [13, 'omen'];
    }

    #[Test]
    #[DataProvider('deck')]
    public function theRoadDeckDealsASceneByItsCopies(int $roll, string $sceneType): void
    {
        $campaign = $this->sessionZeroDone();

        $campaign->pickSceneTypeByOracle($this->dealt($roll), $this->release, self::at());

        self::assertSame($sceneType, self::flowRunOf($campaign)->sceneType());
    }

    #[Test]
    public function aRestInCampRestoresWindUpToEight(): void
    {
        $campaign = $this->sessionZeroDone();
        $this->setWind($campaign, 'ada', 2);
        $campaign->pickSceneTypeByOracle($this->dealt(1), $this->release, self::at());

        $campaign->skipFlowStep('where', $this->release, self::at());
        $campaign->completeFlowStep('rest', StepResult::prompt('Ada sleeps', 'ada'), $this->release, self::at(), new ScriptedRandomNumberGenerator(4));

        self::assertSame(8, $this->wind($campaign, 'ada'), 'A d6 of 4 and the 2 added to Wind 2 reach the cap of 8.');
    }

    #[Test]
    public function aRestInCampNeverRaisesWindPastEight(): void
    {
        $campaign = $this->sessionZeroDone();
        $this->setWind($campaign, 'ada', 7);
        $campaign->pickSceneTypeByOracle($this->dealt(1), $this->release, self::at());

        $campaign->skipFlowStep('where', $this->release, self::at());
        $campaign->completeFlowStep('rest', StepResult::prompt('Ada sleeps', 'ada'), $this->release, self::at(), new ScriptedRandomNumberGenerator(6));

        self::assertSame(8, $this->wind($campaign, 'ada'), 'Wind 7 and a d6 of 6 plus 2 would be 15: the track holds at 8.');
    }

    #[Test]
    public function aFailedCrossingCostsWindAndWinterAndAFumbledOneWindAndBurden(): void
    {
        $failed = $this->crossing(12, 3);
        self::assertSame([5, 1, 0, 0], [$this->wind($failed, 'ada'), $this->tracker($failed, 'winter'), $this->tracker($failed, 'burden'), $this->wounds($failed)], 'Failure takes a d6 of Wind and a step of Winter.');

        $badly = $this->crossing(20, 3);
        self::assertSame([5, 0, 1, 0], [$this->wind($badly, 'ada'), $this->tracker($badly, 'winter'), $this->tracker($badly, 'burden'), $this->wounds($badly)], 'Going badly takes a d6 of Wind and adds a Burden, and no Wound while Wind lasts.');

        $clean = $this->crossing(1, 3);
        self::assertSame([8, 0, 0, 0], [$this->wind($clean, 'ada'), $this->tracker($clean, 'winter'), $this->tracker($clean, 'burden'), $this->wounds($clean)]);
    }

    #[Test]
    public function theWorldTurnClosesWinterByOneAndTheRiskedFordForcesAFordScene(): void
    {
        $campaign = $this->afterCamp();
        $campaign->completeFlowStep('shorter-days', self::prompt('Thin and white'), $this->release, self::at());
        self::assertSame(1, $this->tracker($campaign, 'winter'));

        $campaign->completeFlowStep('road-risk', $this->rolled('road-risks', 4), $this->release, self::at());

        self::assertSame([2, 'ford'], [$this->tracker($campaign, 'winter'), self::flowRunOf($campaign)->forcedNextSceneType()], 'The risen ford adds a second step of Winter and forces the Ford scene.');
        $campaign->pickSceneType('ford', $this->release, self::at());
        self::assertSame('ford', self::flowRunOf($campaign)->sceneType());
    }

    #[Test]
    public function aQuietRiskLeavesTheDeckToDeal(): void
    {
        $campaign = $this->afterCamp();
        $campaign->completeFlowStep('shorter-days', self::prompt('Thin and white'), $this->release, self::at());
        $campaign->completeFlowStep('road-risk', $this->rolled('road-risks', 2), $this->release, self::at());

        self::assertSame([null, null, 1], [self::flowRunOf($campaign)->forcedNextSceneType(), self::flowRunOf($campaign)->sceneType(), $this->tracker($campaign, 'winter')]);
    }

    /**
     * @return iterable<string, array{int, ?int, int}> Winter before the scene ends, the gate's d6 and the index of the phase after the scene
     */
    public static function theGateComesLate(): iterable
    {
        yield 'far from Winter 6 the road goes on and nothing is rolled' => [3, null, 1];
        yield 'Winter 5 is still far and nothing is rolled' => [5, null, 1];
        yield 'Winter 6 is near and the gate stays out of sight on a 4' => [6, 4, 1];
        yield 'Winter 6 is near and the gate comes into sight on a 5' => [6, 5, 2];
        yield 'Winter 9 is still near and the gate stays out of sight on a 4' => [9, 4, 1];
        yield 'Winter 9 is still near and the gate comes into sight on a 6' => [9, 6, 2];
        yield 'near Winter the gate stays out of sight on a 4' => [7, 4, 1];
        yield 'near Winter the gate comes into sight on a 5' => [7, 5, 2];
        yield 'a full Winter brings the gate without a roll' => [10, null, 2];
    }

    #[Test]
    #[DataProvider('theGateComesLate')]
    public function theRoadEndsAtTheGateOnlyLateInWinter(int $winter, ?int $die, int $phase): void
    {
        $campaign = $this->sessionZeroDone();
        $this->setTracker($campaign, 'winter', $winter);
        $campaign->pickSceneTypeByOracle($this->dealt(1), $this->release, self::at());
        $this->endScene($campaign, ['where', 'rest', 'fire']);
        $campaign->skipFlowStep('last', $this->release, self::at());
        if (null !== $die) {
            self::assertSame('gate-in-sight', self::flowRunOf($campaign)->stepKey());
            $campaign->completeFlowStep('gate-in-sight', self::roll('1d6', $die), $this->release, self::at());
        }

        self::assertSame($phase, self::flowRunOf($campaign)->phaseIndex());
    }

    #[Test]
    public function farFromTheGateTheRoadLeavesNoPendingGateStep(): void
    {
        $campaign = $this->sessionZeroDone();
        $this->setTracker($campaign, 'winter', 5);
        $campaign->pickSceneTypeByOracle($this->dealt(1), $this->release, self::at());
        $this->endScene($campaign, ['where', 'rest', 'fire']);
        $campaign->skipFlowStep('last', $this->release, self::at());

        self::assertNotSame('gate-in-sight', self::flowRunOf($campaign)->stepKey());
        self::assertSame('shorter-days', self::flowRunOf($campaign)->stepKey(), 'The world turn comes next, with no roll for the gate.');
    }

    /**
     * @return iterable<string, array{int, int, string}> Winter, Burden and the ending reached
     */
    public static function endings(): iterable
    {
        yield 'in time and light' => [6, 2, 'ending-time'];
        yield 'in time and heavy' => [7, 3, 'ending-price'];
        yield 'late and light' => [8, 0, 'ending-price'];
        yield 'late and heavy' => [10, 3, 'ending-lost'];
    }

    #[Test]
    #[DataProvider('endings')]
    public function theEpilogueReadsWinterAndBurden(int $winter, int $burden, string $ending): void
    {
        $campaign = $this->sessionZeroDone();
        $this->setTracker($campaign, 'winter', $winter);
        $this->setTracker($campaign, 'burden', $burden);
        $this->reachEpilogue($campaign);

        self::assertSame($ending, self::flowRunOf($campaign)->stepKey());
        $campaign->completeFlowStep($ending, self::prompt('It ends'), $this->release, self::at());
        self::assertNull(self::flowRunOf($campaign)->stepKey(), 'The ending does not fall into the next one.');
    }

    #[Test]
    public function anOmenFillsTheSinWithTheLatestBelief(): void
    {
        $campaign = $this->sessionZeroDone();
        $campaign->pickSceneTypeByOracle($this->dealt(13), $this->release, self::at());
        $campaign->completeFlowStep('sign', self::prompt('Crows on the litter'), $this->release, self::at());
        $campaign->completeFlowStep('belief', self::prompt('She sold the shrine its bell and stole it back'), $this->release, self::at());
        self::assertSame('She sold the shrine its bell and stole it back', $campaign->factValues($this->release)['sin']);

        $this->endScene($campaign);
        $campaign->completeFlowStep('aloud', StepResult::choice('yes'), $this->release, self::at());
        self::assertSame(1, $this->tracker($campaign, 'burden'), 'Saying it aloud makes the load heavier.');
    }

    #[Test]
    public function aSecondOmenReplacesTheSin(): void
    {
        $campaign = $this->sessionZeroDone();
        $campaign->pickSceneTypeByOracle($this->dealt(13), $this->release, self::at());
        $campaign->completeFlowStep('sign', self::prompt('Crows on the litter'), $this->release, self::at());
        $campaign->completeFlowStep('belief', self::prompt('She stole the shrine bell'), $this->release, self::at());
        $this->endScene($campaign);
        $campaign->skipFlowStep('aloud', $this->release, self::at());
        $campaign->completeFlowStep('shorter-days', self::prompt('Thin and white'), $this->release, self::at());
        $campaign->completeFlowStep('road-risk', $this->rolled('road-risks', 2), $this->release, self::at());

        $campaign->pickSceneTypeByOracle($this->dealt(13), $this->release, self::at());
        $campaign->completeFlowStep('sign', self::prompt('Rain behind us'), $this->release, self::at());
        $campaign->completeFlowStep('belief', self::prompt('She was framed for the theft'), $this->release, self::at());

        self::assertSame('She was framed for the theft', $campaign->factValues($this->release)['sin'], 'The last Sin written is the Truth.');
    }

    /**
     * Ada and Bo make the bearers, the saint, the shrine and the vow; the FlowRun waits at the road deck.
     */
    private function sessionZeroDone(): Campaign
    {
        $campaign = self::guided($this->release, 'the-long-carry');
        $campaign->startSession(self::at(), $this->release);
        // Traits are 2d6+3 and pennies 2d6*10, rolled in sheet order: Ada is 10, 12, 9 and 60; Bo is 8, 9, 14 and 60.
        foreach (['ada' => [3, 4, 4, 5, 3, 3, 3, 3], 'bo' => [2, 3, 3, 3, 5, 6, 3, 3]] as $id => $faces) {
            $campaign->addCharacter(CharacterId::fromString($id), ucfirst($id), [], [], [], $this->release, CreationMethod::Roll, new ScriptedRandomNumberGenerator(...$faces));
        }

        $campaign->pickSceneType('bearers', $this->release, self::at());
        $campaign->completeFlowStep('who', self::prompt('Debtor and jailer'), $this->release, self::at());
        $bearers = ['ada' => 1, 'bo' => 2];
        foreach ($bearers as $id => $n) {
            $campaign->completeFlowStep('bearer-'.$n, StepResult::character($id), $this->release, self::at(), new ScriptedRandomNumberGenerator(3));
            $campaign->completeFlowStep('outfit-'.$n, StepResult::shop($id, [], []), $this->release, self::at());
        }

        $campaign->skipFlowStep('bearer-3', $this->release, self::at());
        $campaign->completeFlowStep('outfit-3', StepResult::shop('ada', [], []), $this->release, self::at());
        $this->endScene($campaign);
        $campaign->pickSceneType('saint', $this->release, self::at());
        $campaign->completeFlowStep('whose', self::prompt('A pilgrim of the ford, remembered for her laugh'), $this->release, self::at());
        $campaign->completeFlowStep('shrine', self::prompt('The Priory of the Narrow Door'), $this->release, self::at());
        $this->endScene($campaign);
        $campaign->pickSceneType('vow', $this->release, self::at());
        $campaign->completeFlowStep('promise', self::prompt('Pay the debt we owe the shrine'), $this->release, self::at());
        $campaign->skipFlowStep('cost', $this->release, self::at());
        $this->endScene($campaign);

        return $campaign;
    }

    /**
     * A camp scene played and ended, waiting at the world turn's first step.
     */
    private function afterCamp(): Campaign
    {
        $campaign = $this->sessionZeroDone();
        $campaign->pickSceneTypeByOracle($this->dealt(1), $this->release, self::at());
        $this->endScene($campaign, ['where', 'rest', 'fire']);
        $campaign->skipFlowStep('last', $this->release, self::at());

        return $campaign;
    }

    /**
     * Ada, on Wind 8, crosses a ford and rolls the given d20 on Back (10); the Wind effect rolls the given die.
     */
    private function crossing(int $roll, int $windDie): Campaign
    {
        $campaign = $this->sessionZeroDone();
        $this->setWind($campaign, 'ada', 8);
        $campaign->pickSceneTypeByOracle($this->dealt(4), $this->release, self::at());
        $campaign->skipFlowStep('water', $this->release, self::at());
        $values = $campaign->character(CharacterId::fromString('ada'))?->values() ?? self::fail('No character.');
        $result = ($this->release->check('back') ?? self::fail('No check.'))->perform(new ScriptedRandomNumberGenerator($roll), SheetValues::of($this->release->sheet(), $values, []), null, 0);
        $campaign->completeFlowStep('cross', StepResult::check('ada', $result, 'back'), $this->release, self::at(), new ScriptedRandomNumberGenerator($windDie));

        return $campaign;
    }

    /**
     * The road ended by hand and the Shrine Gate played, down to the epilogue's endings.
     */
    private function reachEpilogue(Campaign $campaign): void
    {
        $campaign->moveOn('the-road', $this->release, self::at());
        // A phase that plays one scene starts it by itself: the gate, then the epilogue.
        $campaign->completeFlowStep('gate', self::prompt('A wicket and a bell'), $this->release, self::at());
        $campaign->completeFlowStep('asked', self::prompt('We carried her'), $this->release, self::at());
        $this->endScene($campaign, ['nerve']);
        $campaign->skipFlowStep('through', $this->release, self::at());
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

    private function rolled(string $table, int $total): StepResult
    {
        return StepResult::table($this->release->resolveOracleTable($table, new ScriptedRandomNumberGenerator($total)));
    }

    private function dealt(int $total): OracleTableResult
    {
        return $this->release->resolveOracleTable('road-deck', new ScriptedRandomNumberGenerator($total));
    }

    /**
     * @param array<string, int> $traits
     */
    private function values(array $traits): SheetValues
    {
        return SheetValues::of($this->release->sheet(), $traits + ['back' => 8, 'feet' => 8, 'head' => 8], []);
    }

    private function tracker(Campaign $campaign, string $key): int
    {
        return $campaign->trackerValue($this->release->tracker($key) ?? self::fail('No tracker.'));
    }

    private function setTracker(Campaign $campaign, string $key, int $value): void
    {
        $campaign->setTrackerValue($this->release->tracker($key) ?? self::fail('No tracker.'), $value, self::at());
    }

    private function wind(Campaign $campaign, string $id): int
    {
        $wind = $campaign->character(CharacterId::fromString($id))?->values()['wind'] ?? self::fail('No Wind.');
        \assert(\is_array($wind) && \is_int($wind['current']));

        return $wind['current'];
    }

    private function wounds(Campaign $campaign): int
    {
        $wounds = $campaign->character(CharacterId::fromString('ada'))?->values()['wounds'] ?? self::fail('No Wounds.');
        \assert(\is_int($wounds));

        return $wounds;
    }

    private function setWind(Campaign $campaign, string $id, int $current): void
    {
        $campaign->changeCharacterValue(CharacterId::fromString($id), 'wind', ['current' => $current, 'max' => 8]);
    }
}
