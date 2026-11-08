<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignEffects;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\Campaign\Facts\FactLinkKind;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\SheetChange;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\Campaign\Lists\Npc;
use App\Play\Domain\Campaign\Lists\Thread;
use App\Play\Domain\GameSystem\Flow\SheetEffect;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shipped `ironsworn` preset played through the Campaign (ADR 0049): the prologue, the vows in
 * their five slots, the moves that pay with momentum and the burn, journeys and fights. The dice
 * are scripted: an action roll takes the action die and then the two challenge dice, a progress
 * roll the two challenge dice. The hero has edge 3, heart 2, iron 2, shadow 1, wits 1.
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(Campaign::class)]
final class IronswornPresetFlowTest extends FlowRunTestCase
{
    private const array STATS = ['edge' => 3, 'heart' => 2, 'iron' => 2, 'shadow' => 1, 'wits' => 1];

    private GameSystemSnapshot $release;

    protected function setUp(): void
    {
        /** @var array<string, mixed> $content */
        $content = json_decode((string) file_get_contents(__DIR__.'/../../../../../../presets/ironsworn.json'), true, flags: \JSON_THROW_ON_ERROR);
        $this->release = self::translate($content);
    }

    #[Test]
    public function thePrologueMakesAPlaceTheCharacterBondsAndAVowInASlotWithItsRank(): void
    {
        $campaign = $this->prologue();

        self::assertSame([['Hearth', ['home']]], array_map(static fn (\App\Play\Domain\Campaign\Lists\Place $place): array => [$place->name(), $place->tags()], $campaign->places()));
        self::assertSame([['Mara', ['bond']]], array_map(static fn (Npc $npc): array => [$npc->name(), $npc->tags()], $campaign->npcs()));
        self::assertSame([['Vow 1', ['vow']], ['Vow 2', ['vow']]], array_map(static fn (Thread $thread): array => [$thread->name(), $thread->tags()], $campaign->threads()));
        self::assertSame(['vow-1' => 0, 'vow-1-rank' => 3, 'vow-2' => 0, 'vow-2-rank' => 2, 'bonds' => 1], $this->trackers($campaign, ['vow-1', 'vow-1-rank', 'vow-2', 'vow-2-rank', 'bonds']));
        // The slot Fact holds the Thread, and the adventure phase is next.
        $slot = array_first(array_filter($campaign->facts($this->release), static fn (\App\Play\Domain\Campaign\Facts\Fact $fact): bool => 'vow-1' === $fact->slot())) ?? self::fail('No slot.');
        self::assertSame(FactLinkKind::Thread, $slot->subject()?->kind);
        self::assertSame('adventure', $campaign->flowRunView($this->release)?->progress?->phaseKey);
    }

    /**
     * @return iterable<string, array{int, int}> the rank and the ticks of a milestone
     */
    public static function milestones(): iterable
    {
        yield 'troublesome' => [1, 12];
        yield 'dangerous' => [2, 8];
        yield 'formidable' => [3, 4];
        yield 'extreme' => [4, 2];
        yield 'epic' => [5, 1];
        yield 'a free slot' => [0, 0];
    }

    #[Test]
    #[DataProvider('milestones')]
    public function aMilestoneMarksTheTicksOfTheRankAndNothingOnAFreeSlot(int $rank, int $ticks): void
    {
        $campaign = $this->withTrackers($this->prologue(), ['vow-1-rank' => $rank]);

        $this->scene($campaign, 'milestone');
        $this->step($campaign, 'which', StepResult::choice('vow-1'));

        self::assertSame($ticks, $this->trackers($campaign, ['vow-1'])['vow-1']);
    }

    #[Test]
    public function aSixthVowIsRefusedByTheFullBranch(): void
    {
        $campaign = $this->prologue();
        foreach ([3, 4, 5] as $slot) {
            $this->swear($campaign, $slot, 1, 'Vow '.$slot);
        }
        self::assertCount(5, $campaign->threads());

        $this->scene($campaign, 'swear-vow');

        self::assertSame(['play', 'full'], self::position($campaign));
        $this->skip($campaign, 'full');
        self::assertCount(5, $campaign->threads());
        self::assertSame(['open', null], self::position($campaign));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function ranks(): iterable
    {
        foreach ([1, 2, 3, 4, 5] as $rank) {
            yield 'rank '.$rank => [$rank];
        }
    }

    #[Test]
    #[DataProvider('ranks')]
    public function aStrongFulfilmentEarnsTheRankAndAWeakOneOneLessThenClosesTheVowAndFreesTheSlot(int $rank): void
    {
        $strong = $this->fulfilling($rank, 40);
        $weak = $this->fulfilling($rank, 20);

        $strongChanges = $this->rollCheck($strong, 'check-1', 'fulfill-1', null, 3, 7);
        $weakChanges = $this->rollCheck($weak, 'check-1', 'fulfill-1', null, 2, 9);

        self::assertSame([['experience', 0, $rank]], $this->changes($strongChanges));
        self::assertSame([['experience', 0, max(0, $rank - 1)]], $this->changes($weakChanges));
        foreach ([$strong, $weak] as $campaign) {
            self::assertSame(['Vow 2'], $this->openVows($campaign));
            self::assertSame(['vow-1' => 0, 'vow-1-rank' => 0], $this->trackers($campaign, ['vow-1', 'vow-1-rank']));
        }
    }

    #[Test]
    public function aFreeSlotEndsWithoutRollingAndAMissThenARecommitKeepsFourTicksAndRaisesTheRank(): void
    {
        $free = $this->prologue();
        $this->scene($free, 'fulfill-vow');
        $this->step($free, 'which', StepResult::choice('vow-5'));
        self::assertSame(['open', null], self::position($free));

        $missed = $this->fulfilling(2, 12);
        $this->rollCheck($missed, 'check-1', 'fulfill-1', null, 8, 9);
        self::assertSame(['play', 'undone-1'], self::position($missed));
        $this->step($missed, 'undone-1', StepResult::choice('recommit'));

        self::assertSame(['vow-1' => 4, 'vow-1-rank' => 3], $this->trackers($missed, ['vow-1', 'vow-1-rank']));
        self::assertSame(['Vow 1', 'Vow 2'], $this->openVows($missed));
    }

    #[Test]
    public function forsakingAVowCostsSpiritByItsRankClosesTheThreadAndSendsYouToEndureStress(): void
    {
        $campaign = $this->prologue();
        $this->scene($campaign, 'forsake-vow');

        $changes = $this->step($campaign, 'which', StepResult::choice('vow-1', 'hero'));

        self::assertSame([['spirit', 5, 2]], $this->changes($changes));
        self::assertSame(['Vow 2'], $this->openVows($campaign));
        self::assertSame(['vow-1' => 0, 'vow-1-rank' => 0], $this->trackers($campaign, ['vow-1', 'vow-1-rank']));
        $this->end($campaign);
        self::assertSame(['endure-stress'], $this->offered($campaign));
    }

    #[Test]
    public function forsakingAFreeOrAFulfilledSlotChangesNothingAndStillSendsYouToEndureStress(): void
    {
        // Slot 5 never held a vow; slot 1 held one that was fulfilled, so its Fact still names a closed Thread.
        $campaign = $this->fulfilling(2, 40);
        $this->rollCheck($campaign, 'check-1', 'fulfill-1', null, 3, 7);
        $this->end($campaign);

        foreach (['vow-5', 'vow-1'] as $slot) {
            $this->scene($campaign, 'forsake-vow');
            $changes = $this->step($campaign, 'which', StepResult::choice($slot, 'hero'));

            self::assertSame([['spirit', 5, 5]], $this->changes($changes));
            self::assertSame(['Vow 2'], $this->openVows($campaign));
            $this->end($campaign);
            self::assertSame(['endure-stress'], $this->offered($campaign));
            $this->scene($campaign, 'endure-stress');
            $this->skip($campaign, 'harm');
            $this->skip($campaign, 'roll');
            $this->end($campaign);
        }
    }

    #[Test]
    public function faceDangerPaysMomentumOnAStrongHitAsksForACostOnAWeakOneAndForcesThePriceOnAMiss(): void
    {
        $strong = $this->danger();
        $this->rollCheck($strong, 'roll', 'edge', 'edge', 3, 2, 4);
        self::assertSame(3, $this->momentum($strong));
        self::assertSame(['open', null], self::position($strong));

        $weak = $this->danger();
        $this->rollCheck($weak, 'roll', 'edge', 'edge', 3, 2, 9);
        self::assertSame(['play', 'cost'], self::position($weak));
        $this->step($weak, 'cost', StepResult::choice('momentum', 'hero'));
        self::assertSame(1, $this->momentum($weak));

        $miss = $this->danger();
        $this->rollCheck($miss, 'roll', 'edge', 'edge', 1, 9, 8);
        $this->end($miss);
        self::assertSame(['pay-the-price'], $this->offered($miss));
    }

    /**
     * @return iterable<string, array{int, list<string>, int}> the momentum, the debilities marked and the momentum after a strong hit
     */
    public static function gains(): iterable
    {
        yield 'room to gain' => [5, [], 6];
        yield 'the maximum of 10' => [10, [], 10];
        yield 'two debilities cap it at 8' => [8, ['wounded', 'shaken'], 8];
        yield 'a gain stops at the lowered maximum' => [7, ['wounded', 'shaken'], 8];
    }

    /**
     * @param list<string> $conditions
     */
    #[Test]
    #[DataProvider('gains')]
    public function aMomentumGainStopsAtTheMaximumTheDebilitiesLeave(int $momentum, array $conditions, int $expected): void
    {
        $campaign = $this->danger(['momentum' => $momentum, 'conditions' => $conditions]);

        $this->rollCheck($campaign, 'roll', 'edge', 'edge', 5, 1, 2);

        self::assertSame($expected, $this->momentum($campaign));
    }

    #[Test]
    public function burningOnAPendingStepScoresTheMomentumAndTheCostResetsItToTheDebilityReset(): void
    {
        // Edge 3 and a 1 make a score of 4 against 5 and 7: a miss, which a momentum of 8 turns into a strong hit.
        $campaign = $this->danger(['momentum' => 8, 'conditions' => ['wounded']]);
        $this->rollCheck($campaign, 'roll', 'edge', 'edge', 1, 5, 7);
        self::assertSame(['play', 'roll'], self::position($campaign));
        self::assertNotNull(self::flowRunOf($campaign)->pendingCheck());

        // The burn reads the dice as they were rolled and the momentum on the sheet now.
        $hero = $campaign->character(CharacterId::fromString('hero')) ?? self::fail('No hero.');
        $check = $this->release->check('edge') ?? self::fail('No edge.');
        $values = SheetValues::of($this->release->sheet(), $hero->values(), $campaign->trackerValuesOf($this->release));
        $roll = $check->perform(new ScriptedRandomNumberGenerator(1, 5, 7), $values, null, 0)->challenge ?? self::fail('No challenge.');
        $burn = $check->burn($values, $roll->roll, $roll->score);

        self::assertSame([4, 8, 2, 'strong'], [$roll->score, $burn->score, $burn->total, $burn->band->key]);
        // The cost applies after the momentum was read: it sets the momentum to the reset, 1 with a debility marked.
        foreach ($check->push->effects ?? [] as $effect) {
            self::assertInstanceOf(SheetEffect::class, $effect);
            new CampaignEffects($campaign, $this->release)->applySheetEffect($effect, 'hero');
        }
        self::assertSame(1, $this->momentum($campaign));
        $campaign->resolvePendingFlowCheck('roll', $burn->band->key, $this->release, self::at(), null, $burn->special);

        // The strong hit then pays +1 momentum from the reset.
        self::assertSame([2, ['open', null]], [$this->momentum($campaign), self::position($campaign)]);
    }

    #[Test]
    public function theFirstJourneySceneSetsTheRankALaterOneGoesToTheLegAndArrivalResetsTheJourney(): void
    {
        $campaign = $this->prologue();
        $this->scene($campaign, 'journey');
        $this->step($campaign, 'where', self::prompt('Ashfall'));
        $this->step($campaign, 'rank', StepResult::choice('r2'));
        self::assertSame(['play', 'leg'], self::position($campaign));
        $this->rollCheck($campaign, 'leg', 'wits', null, 2, 2, 9);
        self::assertSame([8, 2, 4, 'arrived'], [...array_values($this->trackers($campaign, ['journey', 'journey-rank'])), $this->supply($campaign), self::position($campaign)[1]]);
        $this->skip($campaign, 'arrived');
        $this->end($campaign);

        $this->scene($campaign, 'journey');
        self::assertSame(['play', 'leg'], self::position($campaign));
        $this->rollCheck($campaign, 'leg', 'wits', null, 2, 2, 9);
        $this->step($campaign, 'arrived', StepResult::choice('yes'));
        $this->rollCheck($campaign, 'reach', 'journey', null, 1, 2);

        self::assertSame(['journey' => 0, 'journey-rank' => 0], $this->trackers($campaign, ['journey', 'journey-rank']));
        self::assertSame(['play', 'boon'], self::position($campaign));
    }

    /**
     * @return iterable<string, array{int, int}> the foe's rank and the ticks of a strong strike
     */
    public static function strikes(): iterable
    {
        yield 'troublesome' => [1, 24];
        yield 'dangerous' => [2, 16];
        yield 'formidable' => [3, 8];
        yield 'extreme' => [4, 4];
        yield 'epic' => [5, 2];
    }

    #[Test]
    #[DataProvider('strikes')]
    public function initiativePicksStrikeOrClashAStrongStrikeMarksDoubleTicksAndReachesTheDecisiveStep(int $rank, int $ticks): void
    {
        $clash = $this->fighting($rank, 0);
        self::assertSame(['play', 'clash'], self::position($clash));

        $strike = $this->fighting($rank, 1);
        self::assertSame(['play', 'strike'], self::position($strike));
        $this->rollCheck($strike, 'strike', 'iron', 'iron', 4, 1, 2);

        self::assertSame(['fight' => $ticks], $this->trackers($strike, ['fight']));
        self::assertSame(['play', 'decisive'], self::position($strike));
    }

    #[Test]
    public function aClashAWeakStrikeAndEndingTheFightMoveTheInitiativeAndTheProgress(): void
    {
        $clash = $this->fighting(2, 0);
        $this->rollCheck($clash, 'clash', 'iron', 'iron', 4, 1, 2);
        self::assertSame([8, 1, 'opening'], [...array_values($this->trackers($clash, ['fight', 'initiative'])), self::position($clash)[1]]);

        $weak = $this->fighting(2, 1);
        $this->rollCheck($weak, 'strike', 'iron', 'iron', 3, 2, 9);
        self::assertSame(['fight' => 8, 'initiative' => 0], $this->trackers($weak, ['fight', 'initiative']));

        $end = $this->fighting(2, 1, 40);
        $this->rollCheck($end, 'strike', 'iron', 'iron', 4, 1, 2);
        $this->step($end, 'decisive', StepResult::choice('finish'));
        $this->rollCheck($end, 'end-fight', 'fight', null, 1, 2);
        self::assertSame(['fight' => 0, 'fight-rank' => 0, 'initiative' => 0], $this->trackers($end, ['fight', 'fight-rank', 'initiative']));
    }

    #[Test]
    public function withNoFightUnderWayTheRoundGoesToNone(): void
    {
        $campaign = $this->prologue();

        $this->scene($campaign, 'fight-round');

        self::assertSame(['play', 'none'], self::position($campaign));
    }

    #[Test]
    public function aMissAtZeroHealthReachesTheTableAndItsFirstEntryForcesFaceDeath(): void
    {
        // Face Danger sends a weak hit's harm to its own scene.
        $campaign = $this->danger();
        $this->rollCheck($campaign, 'roll', 'edge', 'edge', 3, 2, 9);
        $this->step($campaign, 'cost', StepResult::choice('harm'));
        $this->end($campaign);
        $this->scene($campaign, 'endure-harm');
        $this->step($campaign, 'harm', StepResult::choice('l5', 'hero'));
        self::assertSame(0, $campaign->character(CharacterId::fromString('hero'))?->values()['health']['current'] ?? null);

        $this->rollCheck($campaign, 'roll', 'endure-harm', null, 1, 9, 9);
        self::assertSame([1, 'zero'], [$this->momentum($campaign), self::position($campaign)[1]]);
        $this->step($campaign, 'zero', StepResult::choice('table'));
        $this->step($campaign, 'table', StepResult::table($this->release->resolveOracleTable('endure-harm-table', new ScriptedRandomNumberGenerator(7))));
        $this->end($campaign);

        self::assertSame(['face-death'], $this->offered($campaign));
    }

    #[Test]
    public function askTheOracleAndEveryTableTranslate(): void
    {
        self::assertSame('ask-the-oracle', $this->release->likelihoodOracle('ask-the-oracle')->key());
        self::assertCount(8, $this->release->oracleTableKeys());
        foreach ($this->release->oracleTableKeys() as $table) {
            self::assertNotSame('', $this->release->resolveOracleTable($table, new ScriptedRandomNumberGenerator(1))->steps()[0]->text(), $table);
            self::assertNotSame('', $this->release->resolveOracleTable($table, new ScriptedRandomNumberGenerator(100))->steps()[0]->text(), $table);
        }
    }

    #[Test]
    public function swearingAgainAfterACloseCountsOnlyOpenVowsReplacesTheSlotFactAndClosesTheNewThread(): void
    {
        $campaign = $this->prologue();
        foreach ([3, 4, 5] as $slot) {
            $this->swear($campaign, $slot, 1, 'Vow '.$slot);
        }
        $campaign = $this->withTrackers($campaign, ['vow-3' => 40]);
        $this->scene($campaign, 'fulfill-vow');
        $this->step($campaign, 'which', StepResult::choice('vow-3'));
        $this->rollCheck($campaign, 'check-3', 'fulfill-3', null, 3, 7);
        $this->end($campaign);
        self::assertSame(['Vow 1', 'Vow 2', 'Vow 4', 'Vow 5'], $this->openVows($campaign));

        // The closed Thread does not count toward the five.
        $this->swear($campaign, 3, 2, 'Second vow');

        self::assertCount(6, $campaign->threads());
        self::assertSame(['Second vow', 'Vow 1', 'Vow 2', 'Vow 4', 'Vow 5'], $this->openVows($campaign));
        self::assertSame('Second vow', $campaign->factValues($this->release)['vow-3']);
        self::assertSame(2, $this->trackers($campaign, ['vow-3-rank'])['vow-3-rank']);
        $this->scene($campaign, 'swear-vow');
        self::assertSame(['play', 'full'], self::position($campaign));
        $this->skip($campaign, 'full');
        $this->end($campaign);

        // Forsaking the slot closes the new Thread; the old one stays closed.
        $this->scene($campaign, 'forsake-vow');
        $this->step($campaign, 'which', StepResult::choice('vow-3', 'hero'));
        self::assertSame(['Vow 1', 'Vow 2', 'Vow 4', 'Vow 5'], $this->openVows($campaign));
    }

    #[Test]
    public function aSecondJourneyAndASecondFightRefillTheDestinationAndTheFoe(): void
    {
        $campaign = $this->prologue();
        $this->scene($campaign, 'journey');
        $this->step($campaign, 'where', self::prompt('Ashfall'));
        $this->step($campaign, 'rank', StepResult::choice('r1'));
        self::assertSame('Ashfall', $campaign->factValues($this->release)['destination']);
        $this->rollCheck($campaign, 'leg', 'wits', null, 2, 2, 9);
        $this->step($campaign, 'arrived', StepResult::choice('yes'));
        $this->rollCheck($campaign, 'reach', 'journey', null, 1, 2);
        $this->step($campaign, 'boon', StepResult::choice('momentum', 'hero'));
        $this->end($campaign);

        $this->scene($campaign, 'journey');
        self::assertSame(['play', 'where'], self::position($campaign));
        $this->step($campaign, 'where', self::prompt('Dunmere'));
        self::assertSame('Dunmere', $campaign->factValues($this->release)['destination']);
        $this->step($campaign, 'rank', StepResult::choice('r1'));
        $this->rollCheck($campaign, 'leg', 'wits', null, 2, 2, 9);
        $this->skip($campaign, 'arrived');
        $this->end($campaign);

        foreach (['Wolf', 'Bear'] as $foe) {
            $campaign = $this->enter($campaign, $foe);
            self::assertSame($foe, $campaign->factValues($this->release)['foe']);
        }
    }

    #[Test]
    public function aProgressRollFromTicksNotDivisibleByFourScoresTheFloorOfTheFilledBoxes(): void
    {
        // 14 ticks fill 3 boxes: a 2 is beaten and a 3 ties the score, so only a floor of 3 splits them.
        $weak = $this->fulfilling(2, 14);
        $this->rollCheck($weak, 'check-1', 'fulfill-1', null, 2, 9);
        self::assertSame(1, $this->hero($weak)['experience']);

        $miss = $this->fulfilling(2, 14);
        $this->rollCheck($miss, 'check-1', 'fulfill-1', null, 3, 9);
        self::assertSame(['play', 'undone-1'], self::position($miss));
    }

    #[Test]
    public function enterTheFrayFightOnAndAWeakStrikeEachChainIntoAFightRound(): void
    {
        $campaign = $this->prologue();
        $this->scene($campaign, 'enter-the-fray');
        $this->step($campaign, 'foe', self::prompt('Wolf'));
        $this->step($campaign, 'rank', StepResult::choice('r2'));
        // Heart 2 and a 3 make 5 against 2 and 9: a weak hit, so the player picks an edge.
        $this->rollCheck($campaign, 'roll', 'heart', 'heart', 3, 2, 9);
        self::assertSame(['play', 'edge'], self::position($campaign));
        $this->step($campaign, 'edge', StepResult::choice('act'));
        $this->end($campaign);

        self::assertSame(['fight-rank' => 2, 'fight' => 0, 'initiative' => 1], $this->trackers($campaign, ['fight-rank', 'fight', 'initiative']));
        self::assertSame(['fight-round'], $this->offered($campaign));
        $this->scene($campaign, 'fight-round');
        self::assertSame(['play', 'strike'], self::position($campaign));
        // A weak strike marks progress, hands over the initiative and chains into the next round.
        $this->rollCheck($campaign, 'strike', 'iron', 'iron', 2, 1, 9);
        $this->end($campaign);
        self::assertSame(['fight' => 8, 'initiative' => 0], $this->trackers($campaign, ['fight', 'initiative']));
        self::assertSame(['fight-round'], $this->offered($campaign));

        // A strong strike, then fighting on, chains too.
        $campaign = $this->withTrackers($campaign, ['initiative' => 1]);
        $this->scene($campaign, 'fight-round');
        $this->rollCheck($campaign, 'strike', 'iron', 'iron', 4, 1, 2);
        $this->step($campaign, 'decisive', StepResult::choice('on'));
        $this->end($campaign);
        self::assertSame(['fight-round'], $this->offered($campaign));
    }

    /**
     * @return iterable<string, array{int, list<string>, list<int>, int}> the momentum, the conditions marked, the dice and the momentum after
     */
    public static function oaths(): iterable
    {
        yield 'strong hit adds 2' => [2, [], [5, 1, 2], 4];
        yield 'weak hit adds 1' => [2, [], [5, 1, 9], 3];
        yield 'strong hit stops at 10' => [9, [], [5, 1, 2], 10];
        yield 'weak hit stops at 10' => [10, [], [5, 1, 10], 10];
        yield 'strong hit stops at the lowered maximum' => [7, ['wounded', 'shaken'], [5, 1, 2], 8];
        yield 'weak hit stops at the lowered maximum' => [8, ['wounded', 'shaken'], [5, 1, 10], 8];
    }

    /**
     * @param list<string> $conditions
     * @param list<int>    $faces
     */
    #[Test]
    #[DataProvider('oaths')]
    public function theHeartRollOfSwearingAVowPaysMomentumCappedByTheMaximum(int $momentum, array $conditions, array $faces, int $expected): void
    {
        $campaign = $this->prologue(['momentum' => $momentum, 'conditions' => $conditions]);
        $this->swearUntilRoll($campaign, 3);

        // Heart 2 and a 5 make 7: both dice 1 and 2 are beaten (strong), or only the 1 (weak).
        $this->rollCheck($campaign, 'roll', 'heart', null, ...$faces);

        self::assertSame($expected, $this->momentum($campaign));
        self::assertSame(['open', null], self::position($campaign));
    }

    #[Test]
    public function aMissedOathPressesOnForTwoMomentumOrGivesUpAndForsakesTheVow(): void
    {
        $press = $this->prologue();
        $this->swearUntilRoll($press, 3);
        $this->rollCheck($press, 'roll', 'heart', null, 1, 9, 10);
        self::assertSame(['play', 'obstacle'], self::position($press));
        $this->step($press, 'obstacle', StepResult::choice('press', 'hero'));
        self::assertSame(0, $this->momentum($press));
        self::assertSame(['Vow 1', 'Vow 2', 'Vow 3'], $this->openVows($press));

        $giveUp = $this->prologue();
        $this->swearUntilRoll($giveUp, 3);
        $this->rollCheck($giveUp, 'roll', 'heart', null, 1, 9, 10);
        $this->step($giveUp, 'obstacle', StepResult::choice('give-up'));
        $this->end($giveUp);
        self::assertSame(['forsake-vow'], $this->offered($giveUp));
    }

    #[Test]
    public function givingUpAMissedOathDuringThePrologueForsakesTheVowAndThePrologueGoesOn(): void
    {
        $campaign = $this->begin();
        $this->scene($campaign, 'the-ironlands');
        $this->step($campaign, 'place', self::prompt('Hearth'));
        $this->skip($campaign, 'land');
        $this->skip($campaign, 'trouble');
        $this->end($campaign);
        $this->scene($campaign, 'ironsworn');
        $this->step($campaign, 'hero', StepResult::character('hero'));
        $this->skip($campaign, 'bond-1');
        $this->skip($campaign, 'bond-2');
        $this->skip($campaign, 'bond-3');
        $this->end($campaign);
        $this->swearUntilRoll($campaign, 1);
        $this->rollCheck($campaign, 'roll', 'heart', null, 1, 9, 10);
        $this->step($campaign, 'obstacle', StepResult::choice('give-up'));
        $this->end($campaign);
        self::assertSame(['forsake-vow'], $this->offered($campaign));

        $this->scene($campaign, 'forsake-vow');
        $this->step($campaign, 'which', StepResult::choice('vow-1', 'hero'));
        self::assertSame([], $this->openVows($campaign));
        $this->end($campaign);
        $this->scene($campaign, 'endure-stress');
        $this->skip($campaign, 'harm');
        $this->skip($campaign, 'roll');
        $this->end($campaign);

        // The inciting vow is still to be sworn.
        self::assertSame('prologue', $campaign->flowRunView($this->release)?->progress?->phaseKey);
        self::assertSame(['swear-vow'], $this->offered($campaign));
    }

    /**
     * @return iterable<string, array{string, int, string}> the table, the roll and the Scene Type it forces
     */
    public static function forcedEntries(): iterable
    {
        yield 'harm 1' => ['harm', 1, 'face-death'];
        yield 'harm 10' => ['harm', 10, 'face-death'];
        yield 'stress 1' => ['stress', 1, 'face-desolation'];
        yield 'stress 10' => ['stress', 10, 'face-desolation'];
        yield 'stress 11' => ['stress', 11, 'forsake-vow'];
        yield 'stress 25' => ['stress', 25, 'forsake-vow'];
    }

    #[Test]
    #[DataProvider('forcedEntries')]
    public function theTablesForceTheDeadlierSceneTypes(string $kind, int $roll, string $scene): void
    {
        self::assertSame([$scene], $this->offered($this->atTable($kind, $roll)));
    }

    #[Test]
    public function aTableEntryAboveTheForcedRangesForcesNothing(): void
    {
        foreach ([['harm', 11], ['stress', 26]] as [$kind, $roll]) {
            self::assertContains('face-danger', $this->offered($this->atTable($kind, $roll)), $kind);
        }
    }

    #[Test]
    public function theZeroStepsMarkTheirDebilityAndTheBrinkScenesMarkABurden(): void
    {
        $marks = [
            ['harm', 'mark', 'conditions', 'wounded'],
            ['harm', 'bane', 'banes', 'maimed'],
            ['stress', 'mark', 'conditions', 'shaken'],
            ['stress', 'bane', 'banes', 'corrupted'],
        ];
        foreach ($marks as [$kind, $option, $field, $debility]) {
            $campaign = $this->endure($kind);
            $this->rollCheck($campaign, 'roll', 'endure-'.$kind, null, 1, 9, 9);
            $this->step($campaign, 'zero', StepResult::choice($option, 'hero'));
            self::assertSame([$debility], $this->hero($campaign)[$field], $kind.' '.$option);
        }

        foreach ([['harm', 'face-death', 7, 'cursed'], ['stress', 'face-desolation', 5, 'tormented']] as [$kind, $scene, $roll, $burden]) {
            $campaign = $this->atTable($kind, $roll);
            $this->scene($campaign, $scene);
            $this->rollCheck($campaign, 'roll', 'heart', null, 3, 2, 9);
            $this->step($campaign, 'fate', StepResult::choice('price', 'hero'));
            self::assertSame([$burden], $this->hero($campaign)['burdens'], $scene);
        }
    }

    /**
     * @return iterable<string, array{string, string}> the field group and a debility in it
     */
    public static function debilityGroups(): iterable
    {
        yield 'a condition' => ['conditions', 'wounded'];
        yield 'a bane' => ['banes', 'maimed'];
        yield 'a burden' => ['burdens', 'cursed'];
    }

    #[Test]
    #[DataProvider('debilityGroups')]
    public function oneDebilityInAnyGroupLowersTheMomentumMaximumAndReset(string $group, string $debility): void
    {
        $campaign = $this->danger(['momentum' => 10, $group => [$debility]]);

        // A strong hit stops at the maximum of 9.
        $this->rollCheck($campaign, 'roll', 'edge', 'edge', 5, 1, 2);
        self::assertSame(9, $this->momentum($campaign));

        // The burn's cost sets the momentum to the reset of 1.
        $check = $this->release->check('edge') ?? self::fail('No edge.');
        foreach ($check->push->effects ?? [] as $effect) {
            self::assertInstanceOf(SheetEffect::class, $effect);
            new CampaignEffects($campaign, $this->release)->applySheetEffect($effect, 'hero');
        }
        self::assertSame(1, $this->momentum($campaign));
    }

    #[Test]
    public function overwritingASlotInUseClosesItsOldThreadAtNoCostAndFreesTheCount(): void
    {
        $campaign = $this->prologue();
        $this->swear($campaign, 1, 1, 'Replacement');

        // The old Thread is closed, not left open behind the new one.
        self::assertSame(['Replacement', 'Vow 2'], $this->openVows($campaign));
        self::assertSame(['vow-1' => 0, 'vow-1-rank' => 1], $this->trackers($campaign, ['vow-1', 'vow-1-rank']));
        $this->swear($campaign, 3, 1, 'Vow 3');
        $this->swear($campaign, 4, 1, 'Vow 4');

        // Four are open, so a fifth may still be sworn although five Threads were opened in all.
        self::assertCount(5, $campaign->threads());
        $this->scene($campaign, 'swear-vow');
        self::assertSame(['play', 'vow'], self::position($campaign));
    }

    #[Test]
    public function theSlotPromptReadsFreeForSlotsNothingFilledYet(): void
    {
        $campaign = $this->begin();
        $this->scene($campaign, 'the-ironlands');
        $this->step($campaign, 'place', self::prompt('Hearth'));
        $this->skip($campaign, 'land');
        $this->skip($campaign, 'trouble');
        $this->end($campaign);
        $this->scene($campaign, 'ironsworn');
        $this->step($campaign, 'hero', StepResult::character('hero'));
        foreach (['bond-1', 'bond-2', 'bond-3'] as $bond) {
            $this->skip($campaign, $bond);
        }
        $this->end($campaign);
        $this->scene($campaign, 'swear-vow');
        $this->step($campaign, 'vow', self::prompt('Find the lost bell'));
        $this->step($campaign, 'rank', StepResult::choice('r1'));

        $prompt = $campaign->flowRunView($this->release)?->step->prompt ?? self::fail('No prompt.');

        // A tracker with levels renders as its level label, so the prompt names the Free slots by their label.
        self::assertStringStartsWith("Vow 1:  (rank Free, 0 of 40 ticks)\nVow 2:  (rank Free, 0 of 40 ticks)", $prompt);
        self::assertStringEndsWith('Pick a Free slot, which holds no vow.', $prompt);
    }

    #[Test]
    public function payThePriceOffersLosingTwoMomentum(): void
    {
        $campaign = $this->danger();
        $this->rollCheck($campaign, 'roll', 'edge', 'edge', 1, 9, 8);
        $this->end($campaign);
        $this->scene($campaign, 'pay-the-price');
        $this->skip($campaign, 'table');

        $this->step($campaign, 'cost', StepResult::choice('momentum-2', 'hero'));

        self::assertSame(0, $this->momentum($campaign));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function begin(array $values = []): Campaign
    {
        $campaign = self::guided($this->release, 'ironsworn');
        $campaign->addCharacter(CharacterId::fromString('hero'), 'Aldis', [], [], [...self::STATS, ...$values], $this->release);
        $campaign->startSession(self::at(), $this->release);

        return $campaign;
    }

    /**
     * A campaign through the prologue, at the adventure's scene pick, with the two vows sworn in slots 1 and 2.
     *
     * @param array<string, mixed> $values the hero's sheet values
     * @param list<int>            $ranks
     */
    private function prologue(array $values = [], array $ranks = [3, 2]): Campaign
    {
        $campaign = $this->begin($values);
        $this->scene($campaign, 'the-ironlands');
        $this->step($campaign, 'place', self::prompt('Hearth'));
        $this->skip($campaign, 'land');
        $this->skip($campaign, 'trouble');
        $this->end($campaign);
        $this->scene($campaign, 'ironsworn');
        $this->step($campaign, 'hero', StepResult::character('hero'));
        $this->step($campaign, 'bond-1', self::prompt('Mara'));
        $this->skip($campaign, 'bond-2');
        $this->skip($campaign, 'bond-3');
        $this->end($campaign);
        foreach ($ranks as $index => $rank) {
            $this->swear($campaign, $index + 1, $rank, 'Vow '.($index + 1));
        }

        return $campaign;
    }

    private function swear(Campaign $campaign, int $slot, int $rank, string $name): void
    {
        $this->scene($campaign, 'swear-vow');
        $this->step($campaign, 'vow', self::prompt($name));
        $this->step($campaign, 'rank', StepResult::choice('r'.$rank));
        $this->step($campaign, 'slot', StepResult::choice('s'.$slot));
        $this->skip($campaign, 'roll');
        $this->end($campaign);
    }

    /**
     * Vow 1 at the given rank and ticks, at its fulfilment roll.
     */
    private function fulfilling(int $rank, int $ticks): Campaign
    {
        $campaign = $this->withTrackers($this->prologue(), ['vow-1-rank' => $rank, 'vow-1' => $ticks]);
        $this->scene($campaign, 'fulfill-vow');
        $this->step($campaign, 'which', StepResult::choice('vow-1'));
        self::assertSame(['play', 'check-1'], self::position($campaign));

        return $campaign;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function danger(array $values = []): Campaign
    {
        $campaign = $this->prologue($values);
        $this->scene($campaign, 'face-danger');
        $this->step($campaign, 'what', self::prompt('Cross the rope bridge'));

        return $campaign;
    }

    /**
     * A fight against a foe of the rank, at the round's first step.
     */
    private function fighting(int $rank, int $initiative, int $progress = 0): Campaign
    {
        $campaign = $this->withTrackers($this->prologue(), ['fight-rank' => $rank, 'initiative' => $initiative, 'fight' => $progress]);
        $this->scene($campaign, 'fight-round');

        return $campaign;
    }

    private function scene(Campaign $campaign, string $sceneType): void
    {
        $campaign->pickSceneType($sceneType, $this->release, self::at());
        $campaign->editCurrentSceneCast([], [CharacterId::fromString('hero')]);
    }

    private function end(Campaign $campaign): void
    {
        $campaign->endFlowScene($campaign->currentScene()?->number() ?? self::fail('No scene.'), $this->release, self::at());
    }

    /**
     * @return list<SheetChange>
     */
    private function step(Campaign $campaign, string $step, StepResult $result): array
    {
        return $campaign->completeFlowStep($step, $result, $this->release, self::at());
    }

    private function skip(Campaign $campaign, string $step): void
    {
        $campaign->skipFlowStep($step, $this->release, self::at());
    }

    /**
     * Rolls the check as the hero with the faces scripted (the action die first when there is one).
     *
     * @return list<SheetChange>
     */
    private function rollCheck(Campaign $campaign, string $step, string $check, ?string $key, int ...$faces): array
    {
        $campaign->flowCheckDifficulty($step, 'hero', null, $this->release, self::at());
        $hero = $campaign->character(CharacterId::fromString('hero')) ?? self::fail('No hero.');
        $values = SheetValues::of($this->release->sheet(), $hero->values(), $campaign->trackerValuesOf($this->release));
        $result = ($this->release->check($check) ?? self::fail('No check.'))->perform(new ScriptedRandomNumberGenerator(...$faces), $values, null, 0);

        return $campaign->completeFlowStep($step, StepResult::check('hero', $result, $key), $this->release, self::at(), entryId: 'entry-1');
    }

    /**
     * @param array<string, int> $values
     */
    private function withTrackers(Campaign $campaign, array $values): Campaign
    {
        return Campaign::reconstitute($campaign->id(), $campaign->ownerId(), $campaign->name(), $campaign->pinnedRelease(), $campaign->createdAt(), $campaign->sessions(), [...$campaign->trackerValuesOf($this->release), ...$values], $campaign->flowKey(), $campaign->flowRun(), $campaign->npcs(), $campaign->threads(), $campaign->facts($this->release), $campaign->characters(), $campaign->places());
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, int>
     */
    private function trackers(Campaign $campaign, array $keys): array
    {
        $values = $campaign->trackerValuesOf($this->release);

        return array_combine($keys, array_map(static fn (string $key): int => $values[$key], $keys));
    }

    /**
     * @return list<string> the names of the open vows
     */
    private function openVows(Campaign $campaign): array
    {
        $open = array_filter($campaign->threads(), static fn (Thread $thread): bool => $thread->isOpen());
        $names = array_map(static fn (Thread $thread): string => $thread->name(), $open);
        sort($names);

        return $names;
    }

    private function momentum(Campaign $campaign): int
    {
        $momentum = $campaign->character(CharacterId::fromString('hero'))?->values()['momentum'] ?? self::fail('No momentum.');
        \assert(\is_int($momentum));

        return $momentum;
    }

    private function supply(Campaign $campaign): int
    {
        $supply = $campaign->character(CharacterId::fromString('hero'))?->values()['supply'] ?? self::fail('No supply.');
        \assert(\is_array($supply) && \is_int($supply['current']));

        return $supply['current'];
    }

    /**
     * @return list<string> the Scene Types the scene pick offers
     */
    private function offered(Campaign $campaign): array
    {
        return array_map(static fn ($type): string => $type->key, $campaign->flowRunView($this->release)?->pick->cards ?? []);
    }

    /**
     * @return array<string, mixed> the hero's sheet values
     */
    private function hero(Campaign $campaign): array
    {
        return $campaign->character(CharacterId::fromString('hero'))?->values() ?? self::fail('No hero.');
    }

    /**
     * The swear-vow scene at its heart roll, the vow named and ranked and the slot picked.
     */
    private function swearUntilRoll(Campaign $campaign, int $slot): void
    {
        $this->scene($campaign, 'swear-vow');
        $this->step($campaign, 'vow', self::prompt('Vow '.$slot));
        $this->step($campaign, 'rank', StepResult::choice('r1'));
        $this->step($campaign, 'slot', StepResult::choice('s'.$slot));
        self::assertSame(['play', 'roll'], self::position($campaign));
    }

    /**
     * Enter the Fray against the foe: a weak hit and the edge that chains into the first round, which is
     * ended with the fight over (the rank at 0) so that the next scene may be picked.
     */
    private function enter(Campaign $campaign, string $foe): Campaign
    {
        $this->scene($campaign, 'enter-the-fray');
        $this->step($campaign, 'foe', self::prompt($foe));
        $this->step($campaign, 'rank', StepResult::choice('r1'));
        $this->rollCheck($campaign, 'roll', 'heart', 'heart', 3, 2, 9);
        $this->step($campaign, 'edge', StepResult::choice('act'));
        $this->end($campaign);
        $campaign = $this->withTrackers($campaign, ['fight-rank' => 0]);
        $this->scene($campaign, 'fight-round');
        $this->skip($campaign, 'none');
        $this->end($campaign);

        return $campaign;
    }

    /**
     * Face Danger's weak hit sends you to the Endure scene of the kind ("harm" or "stress"), at its first step.
     */
    private function endure(string $kind): Campaign
    {
        $campaign = $this->danger();
        $this->rollCheck($campaign, 'roll', 'edge', 'edge', 3, 2, 9);
        $this->step($campaign, 'cost', StepResult::choice($kind));
        $this->end($campaign);
        $this->scene($campaign, 'endure-'.$kind);
        $this->skip($campaign, 'harm');

        return $campaign;
    }

    /**
     * An Endure scene taken to its table with the table roll scripted, and ended: what the table forces is offered.
     */
    private function atTable(string $kind, int $roll): Campaign
    {
        $campaign = $this->endure($kind);
        $this->rollCheck($campaign, 'roll', 'endure-'.$kind, null, 1, 9, 9);
        $this->step($campaign, 'zero', StepResult::choice('table'));
        $this->step($campaign, 'table', StepResult::table($this->release->resolveOracleTable('endure-'.$kind.'-table', new ScriptedRandomNumberGenerator($roll))));
        $this->end($campaign);

        return $campaign;
    }

    /**
     * @param list<SheetChange> $changes
     *
     * @return list<array{string, mixed, mixed}>
     */
    private function changes(array $changes): array
    {
        return array_map(static fn (SheetChange $change): array => [$change->field, $change->before, $change->after], $changes);
    }
}
