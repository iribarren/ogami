<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunStatus;
use App\Play\Domain\Campaign\FlowRun\PickCandidate;
use App\Play\Domain\Campaign\FlowRun\PickedItem;
use App\Play\Domain\Campaign\FlowRun\ShopLine;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\Campaign\InsufficientFunds;
use App\Play\Domain\Campaign\Lists\Npc;
use App\Play\Domain\Campaign\Lists\Thread;
use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Randomness\Domain\Oracle\YesNoAnswer;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shipped `night-job` preset's campaign flow "Making a Name" (flow example 4) played through the
 * Campaign: street zero, a gig as a chain of scenes, the world turn's hit squad, the gate between the
 * acts, Month's end with its rent, the Night Market and the finale's betrayal.
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(Campaign::class)]
final class NightJobCampaignPresetFlowTest extends FlowRunTestCase
{
    private GameSystemSnapshot $release;

    protected function setUp(): void
    {
        /** @var array<string, mixed> $content */
        $content = json_decode((string) file_get_contents(__DIR__.'/../../../../../../presets/night-job.json'), true, flags: \JSON_THROW_ON_ERROR);
        $this->release = self::translate($content);
    }

    #[Test]
    public function streetZeroMakesTheCharacterTheTurfAndTheFixerThenOpensActOne(): void
    {
        $campaign = $this->guidedCampaign();
        $campaign->startSession(self::at(), $this->release);

        $campaign->pickSceneType('edgerunner', $this->release, self::at());
        $this->createYourCharacter($campaign);
        $this->endScene($campaign);
        $campaign->pickSceneType('turf', $this->release, self::at());
        $campaign->completeFlowStep('block', self::prompt('Neon Row'), $this->release, self::at());
        $campaign->completeFlowStep('crew-name', self::prompt('The Static'), $this->release, self::at());
        $this->endScene($campaign);
        $campaign->pickSceneType('fixer', $this->release, self::at());
        $campaign->completeFlowStep('fixer', self::prompt('Mama Lux'), $this->release, self::at());
        $this->endScene($campaign);

        $facts = $campaign->factValues($this->release);
        self::assertSame(['Neon Row', 'The Static', 'Mama Lux'], [$facts['turf'], $facts['crew-name'], $facts['fixer']]);
        self::assertSame([['Mama Lux', ['fixer']]], array_map(static fn (Npc $npc): array => [$npc->name(), $npc->tags()], $campaign->npcs()));
        // Vi starts with the sheet's 500 eb, and the guide waits at Act 1's scene pick.
        self::assertSame(500, $campaign->character(CharacterId::fromString('vi'))?->values()['eddies']);
        self::assertSame([1, 'Act 1: Making a name'], [self::flowRunOf($campaign)->phaseIndex(), $this->release->flow('campaign')?->phases[1]->act]);
        self::assertSame('making-a-name', $this->release->flow('campaign')?->phases[self::flowRunOf($campaign)->phaseIndex()]->key);
    }

    #[Test]
    public function aGigIsAChainOfScenesFromTheOfferToThePayday(): void
    {
        $campaign = $this->actOne();
        $campaign->setTrackerValue($this->tracker('alarm'), 3, self::at());

        // The offer resets the alarm; taking it chains into the legwork.
        $campaign->pickSceneType('gig-offer', $this->release, self::at());
        $campaign->completeFlowStep('client', self::prompt('Mr. Kline'), $this->release, self::at());
        $campaign->completeFlowStep('gig', self::prompt('Lift a prototype'), $this->release, self::at());
        self::assertSame(0, $campaign->trackerValue($this->tracker('alarm')));
        $this->endScene($campaign);
        $campaign->completeFlowStep('take-it', StepResult::choice('yes'), $this->release, self::at());
        $this->quietStreet($campaign);
        self::assertSame('legwork', self::flowRunOf($campaign)->forcedNextSceneType());

        // An edge from the legwork, then "go" chains into the job.
        $campaign->pickSceneType('legwork', $this->release, self::at());
        $campaign->skipFlowStep('dig', $this->release, self::at());
        $campaign->skipFlowStep('find', $this->release, self::at());
        $this->endScene($campaign);
        $campaign->completeFlowStep('gain-edge', StepResult::choice('yes'), $this->release, self::at());
        $campaign->completeFlowStep('more-or-go', StepResult::choice('go'), $this->release, self::at());
        $this->quietStreet($campaign);

        // A "no" and a patrol raise the alarm to 2; the spare edge lowers it to 1.
        $campaign->pickSceneType('job', $this->release, self::at());
        $campaign->completeFlowStep('plan', self::oracle(YesNoAnswer::No), $this->release, self::at());
        $campaign->completeFlowStep('trouble', $this->rolled('complications', 1), $this->release, self::at());
        self::assertSame(2, $campaign->trackerValue($this->tracker('alarm')));
        $this->endScene($campaign);
        $campaign->completeFlowStep('spend-edge', StepResult::choice('yes'), $this->release, self::at());
        self::assertSame([0, 1], [$campaign->trackerValue($this->tracker('edge')), $campaign->trackerValue($this->tracker('alarm'))]);
        $campaign->completeFlowStep('ending', self::prompt('The van burns'), $this->release, self::at());
        $this->quietStreet($campaign);

        // A clean job earns 2 rep, and the pay is 200 eb plus 50 eb a point of rep, for the Character the player picks.
        $campaign->pickSceneType('gig-payday', $this->release, self::at());
        self::assertSame(2, $campaign->trackerValue($this->tracker('rep')));
        $campaign->completeFlowStep('collect', StepResult::choice('collect', 'vi'), $this->release, self::at());
        self::assertSame(800, $campaign->character(CharacterId::fromString('vi'))?->values()['eddies']);
        $this->endScene($campaign);
        $campaign->completeFlowStep('loose-ends', self::prompt('The driver talked'), $this->release, self::at());
        self::assertSame(['The driver talked'], array_map(static fn (Thread $thread): string => $thread->name(), $campaign->threads()));
    }

    #[Test]
    public function aMessyJobEarnsOnlyOneRep(): void
    {
        $campaign = $this->actOne();
        $campaign->pickSceneType('gig-offer', $this->release, self::at());
        $this->endScene($campaign, skipping: ['client', 'gig']);
        $campaign->completeFlowStep('take-it', StepResult::choice('yes'), $this->release, self::at());
        $this->quietStreet($campaign);
        $campaign->pickSceneType('legwork', $this->release, self::at());
        $this->endScene($campaign, skipping: ['dig', 'find']);
        $campaign->skipFlowStep('gain-edge', $this->release, self::at());
        $campaign->completeFlowStep('more-or-go', StepResult::choice('go'), $this->release, self::at());
        $this->quietStreet($campaign);

        // An exceptional no (+2) and "Spotted!" (+2) fill the alarm to 4 with no edge to spend.
        $campaign->pickSceneType('job', $this->release, self::at());
        $campaign->completeFlowStep('plan', self::oracle(YesNoAnswer::ExceptionalNo), $this->release, self::at());
        $campaign->completeFlowStep('trouble', $this->rolled('complications', 3), $this->release, self::at());
        $this->endScene($campaign);
        $campaign->completeFlowStep('ending', self::prompt('Sirens'), $this->release, self::at());
        $this->quietStreet($campaign);
        $campaign->pickSceneType('gig-payday', $this->release, self::at());

        self::assertSame([4, 1], [$campaign->trackerValue($this->tracker('alarm')), $campaign->trackerValue($this->tracker('rep'))]);
    }

    #[Test]
    public function aWorldTurnThatPushesTheHeatPastSevenSendsAHitSquadThatCoolsItToFour(): void
    {
        $campaign = $this->actOne();
        $campaign->setTrackerValue($this->tracker('heat'), 7, self::at());
        $campaign->pickSceneType('social', $this->release, self::at());
        $this->endScene($campaign, skipping: ['talk', 'reaction']);

        // 5 on the die opens the street events; the third entry is a corp sweep (heat +1), so the heat reaches 8.
        $campaign->completeFlowStep('street', self::roll('1d6', 5), $this->release, self::at());
        $campaign->completeFlowStep('event', $this->rolled('street-events', 3), $this->release, self::at());

        self::assertSame([8, 'hit-squad'], [$campaign->trackerValue($this->tracker('heat')), self::flowRunOf($campaign)->forcedNextSceneType()]);
        $campaign->pickSceneType('hit-squad', $this->release, self::at());
        $campaign->completeFlowStep('found', self::prompt('Arasaka remembers'), $this->release, self::at());
        $campaign->completeFlowStep('squad', $this->rolled('security-response', 2), $this->release, self::at());
        $this->endScene($campaign);
        $campaign->completeFlowStep('clear', self::prompt('Over the rooftops'), $this->release, self::at());

        self::assertSame(4, $campaign->trackerValue($this->tracker('heat')));
    }

    #[Test]
    public function aGrudgeOnTheStreetCreatesTheRivalAndFillsTheNemesis(): void
    {
        $campaign = $this->actOne();
        $campaign->pickSceneType('social', $this->release, self::at());
        $this->endScene($campaign, skipping: ['talk', 'reaction']);

        $campaign->completeFlowStep('street', self::roll('1d6', 6), $this->release, self::at());
        $campaign->completeFlowStep('event', $this->rolled('street-events', 5), $this->release, self::at());
        $campaign->completeFlowStep('grudge', self::prompt('Razor'), $this->release, self::at());

        self::assertSame('Razor', $campaign->factValues($this->release)['nemesis']);
        self::assertContains(['Razor', ['rival']], array_map(static fn (Npc $npc): array => [$npc->name(), $npc->tags()], $campaign->npcs()));
    }

    #[Test]
    public function aStrongNameAndTwoCrewLetThePlayerGoBigIntoPlanningWhichResetsTheJobTrackers(): void
    {
        $campaign = $this->actOne();
        $campaign->setTrackerValue($this->tracker('rep'), 5, self::at());
        $campaign->setTrackerValue($this->tracker('alarm'), 4, self::at());
        $campaign->setTrackerValue($this->tracker('edge'), 2, self::at());
        $campaign->setTrackerValue($this->tracker('objective'), 1, self::at());

        // One crew member is not enough: the gate closes without asking.
        $this->recruit($campaign, 'Ada', 'netrunner');
        self::assertSame('street', self::flowRunOf($campaign)->stepKey());
        $this->quietStreet($campaign);

        // The second one opens "Go big?".
        $this->recruit($campaign, 'Rook', 'nomad');
        self::assertSame('go-big', self::flowRunOf($campaign)->stepKey());
        self::assertSame([['Mama Lux', ['fixer']], ['Ada', ['crew', 'netrunner']], ['Rook', ['crew', 'nomad']]], array_map(static fn (Npc $npc): array => [$npc->name(), $npc->tags()], $campaign->npcs()));
        $campaign->completeFlowStep('go-big', StepResult::choice('yes'), $this->release, self::at());

        // Act 2 opens with the big job, and the pressure from Act 1 does not carry over.
        self::assertSame('big-job', self::flowRunOf($campaign)->stepKey());
        $campaign->completeFlowStep('big-job', self::prompt('The Arasaka vault'), $this->release, self::at());
        self::assertSame(['The Arasaka vault', 2, 0, 0, 0], [$campaign->factValues($this->release)['big-job'], self::flowRunOf($campaign)->phaseIndex(), $campaign->trackerValue($this->tracker('alarm')), $campaign->trackerValue($this->tracker('edge')), $campaign->trackerValue($this->tracker('objective'))]);
        self::assertSame('Act 2: The big job', $this->release->flow('campaign')?->phases[2]->act);
    }

    #[Test]
    public function aLowRepKeepsTheGateShutEvenWithACrew(): void
    {
        $campaign = $this->actOne();
        $campaign->setTrackerValue($this->tracker('rep'), 4, self::at());

        $this->recruit($campaign, 'Ada', 'solo');
        $this->quietStreet($campaign);
        $this->recruit($campaign, 'Rook', 'nomad');

        self::assertSame('street', self::flowRunOf($campaign)->stepKey());
    }

    #[Test]
    public function aMonthsEndRentTheCharacterCannotPayIsRefusedAndSkippingItCostsLifestyleAndOwesAFixer(): void
    {
        $campaign = $this->actOne();
        $campaign->setTrackerValue($this->tracker('housing'), 1, self::at());
        $campaign->setTrackerValue($this->tracker('month'), 3, self::at());

        // The fourth Downtime fills the Month clock and the rent comes due.
        $campaign->pickSceneType('downtime', $this->release, self::at());
        $this->endScene($campaign, skipping: ['spend']);
        $campaign->completeFlowStep('days', self::prompt('Lying low'), $this->release, self::at());
        $campaign->completeFlowStep('market', StepResult::choice('no'), $this->release, self::at());
        $this->quietStreet($campaign);
        $campaign->pickSceneType('months-end', $this->release, self::at());

        // A cargo container costs 1,000 eb and Vi has 500: nothing changes.
        self::assertSame('rent-container', self::flowRunOf($campaign)->stepKey());
        $unchanged = clone $campaign;
        try {
            $campaign->completeFlowStep('rent-container', StepResult::choice('pay', 'vi'), $this->release, self::at());
            self::fail('The rent was paid with 500 eb.');
        } catch (InsufficientFunds $insufficientFunds) {
            self::assertSame(['eddies', 500, -500, 0], [$insufficientFunds->field, $insufficientFunds->before, $insufficientFunds->attempted, $insufficientFunds->floor]);
        }

        self::assertEquals($unchanged, $campaign);

        // Skipping it lowers the lifestyle, restarts the month and borrows from the fixer.
        $campaign->completeFlowStep('rent-container', StepResult::choice('skip'), $this->release, self::at());
        self::assertSame([0, 0, 'debt'], [$campaign->trackerValue($this->tracker('lifestyle')), $campaign->trackerValue($this->tracker('month')), self::flowRunOf($campaign)->stepKey()]);
        $campaign->completeFlowStep('debt', $this->pickAll($campaign), $this->release, self::at());
        self::assertSame(['Debt to Mama Lux'], array_map(static fn (Thread $thread): string => $thread->name(), $campaign->threads()));
        $campaign->skipFlowStep('eat', $this->release, self::at());
        $campaign->completeFlowStep('move-up', StepResult::choice('yes'), $this->release, self::at());
        self::assertSame(2, $campaign->trackerValue($this->tracker('housing')));
    }

    #[Test]
    public function payingTheRentTakesTheEddiesRestartsTheMonthAndLetsTheCharacterEatBetter(): void
    {
        $campaign = $this->atMonthsEnd();

        // The cube hotel costs 500 eb and Vi has exactly 500.
        self::assertSame('rent-hotel', self::flowRunOf($campaign)->stepKey());
        $campaign->completeFlowStep('rent-hotel', StepResult::choice('pay', 'vi'), $this->release, self::at());
        self::assertSame([0, 0, 'eat'], [$campaign->character(CharacterId::fromString('vi'))?->values()['eddies'], $campaign->trackerValue($this->tracker('month')), self::flowRunOf($campaign)->stepKey()]);

        // Eating better costs 300 eb she no longer has: refused, and she keeps her lifestyle.
        $this->expectException(InsufficientFunds::class);
        $campaign->completeFlowStep('eat', StepResult::choice('yes', 'vi'), $this->release, self::at());
    }

    #[Test]
    public function aNightMarketPurchaseFromGearUpLandsInTheInventoryThatAcceptsItsCategory(): void
    {
        $campaign = $this->inPlanning();
        $campaign->pickSceneType('gear-up', $this->release, self::at());
        $this->endScene($campaign, skipping: ['need']);
        $campaign->completeFlowStep('market', StepResult::choice('yes'), $this->release, self::at());

        $campaign->pickSceneType('night-market', $this->release, self::at());
        $campaign->skipFlowStep('crowd', $this->release, self::at(), new ScriptedRandomNumberGenerator(...self::STALLS));
        $onSale = array_map(static fn (\App\Play\Domain\GameSystem\GearEntry $entry): string => $entry->key, $campaign->activeFlowRunView($this->release)->step?->shop->onSale ?? []);
        self::assertSame(['medkit', 'cyberoptic'], array_values(array_intersect(['medkit', 'cyberoptic'], $onSale)));

        $campaign->completeFlowStep('stalls', StepResult::shop('vi', [ShopLine::buy('medkit', 1), ShopLine::buy('cyberoptic', 1)], []), $this->release, self::at());

        $values = $campaign->character(CharacterId::fromString('vi'))?->values() ?? [];
        self::assertSame([[['gear' => 'medkit', 'quantity' => 1]], [['gear' => 'cyberoptic', 'quantity' => 1]], 300], [$values['gear'], $values['cyberware'], $values['eddies']]);
    }

    #[Test]
    public function aDowntimeThatVisitsTheNightMarketStillEndsTheMonthWhenTheRentIsDue(): void
    {
        $campaign = $this->actOne();
        $campaign->setTrackerValue($this->tracker('month'), 3, self::at());
        $campaign->pickSceneType('downtime', $this->release, self::at());
        $this->endScene($campaign, skipping: ['spend']);
        $campaign->completeFlowStep('days', self::prompt('Lying low'), $this->release, self::at());
        $campaign->completeFlowStep('market', StepResult::choice('yes'), $this->release, self::at());
        $this->quietStreet($campaign);

        // The market comes first; the Month clock is full, so it leads on to Month's end.
        self::assertSame('night-market', self::flowRunOf($campaign)->forcedNextSceneType());
        $campaign->pickSceneType('night-market', $this->release, self::at());
        $campaign->skipFlowStep('crowd', $this->release, self::at(), new ScriptedRandomNumberGenerator(...self::STALLS));
        $campaign->completeFlowStep('stalls', StepResult::shop('vi', [], []), $this->release, self::at());
        $this->endScene($campaign);
        $this->quietStreet($campaign);

        self::assertSame('months-end', self::flowRunOf($campaign)->forcedNextSceneType());
    }

    #[Test]
    public function theBetrayalPicksTheFixerFirstAndThenFallsBackToAClient(): void
    {
        $withFixer = $this->atTheFinale(true);
        $candidates = $this->candidates($withFixer);
        self::assertSame(['Mama Lux'], array_map(static fn (PickCandidate $candidate): string => $candidate->name, $candidates));
        $withFixer->completeFlowStep('traitor', $this->pickAll($withFixer), $this->release, self::at());
        self::assertSame('twist', self::flowRunOf($withFixer)->stepKey());

        // No fixer in the cast: the same scene asks about a client, then rolls the twist.
        $campaign = $this->atTheFinale(false);
        self::assertSame('traitor', self::flowRunOf($campaign)->stepKey());
        $campaign->completeFlowStep('traitor', StepResult::pick(ItemKind::Npc, []), $this->release, self::at());
        self::assertSame(['traitor-client', ['Mr. Kline']], [self::flowRunOf($campaign)->stepKey(), array_map(static fn (PickCandidate $candidate): string => $candidate->name, $this->candidates($campaign))]);
        $campaign->completeFlowStep('traitor-client', $this->pickAll($campaign), $this->release, self::at());
        self::assertSame('twist', self::flowRunOf($campaign)->stepKey());
        $campaign->completeFlowStep('twist', $this->rolled('twists', 3), $this->release, self::at());
        self::assertSame('Your fixer sold the crew for a clean slate', $campaign->factValues($this->release)['twist']);

        // The showdown and the epilogue close the Flow.
        $this->endScene($campaign);
        $campaign->pickSceneType('showdown', $this->release, self::at());
        $campaign->completeFlowStep('where', self::prompt('The rooftop'), $this->release, self::at());
        $this->endScene($campaign, skipping: ['odds', 'price']);
        $campaign->pickSceneType('epilogue', $this->release, self::at());
        $this->endScene($campaign, skipping: ['worth-it', 'standing']);
        self::assertSame(FlowRunStatus::Completed, self::flowRunOf($campaign)->status());
    }

    /**
     * Dice for the Night Market: a 4 on the stock die gives 6 entries of the ten on offer, then the medkit (5th), the
     * cyberoptic (7th of the nine left) and the first four of the rest are drawn.
     */
    private const array STALLS = [4, 5, 7, 1, 1, 1, 1];

    private function guidedCampaign(): Campaign
    {
        return self::guided($this->release, 'campaign');
    }

    private function tracker(string $key): \App\Play\Domain\GameSystem\Tracker
    {
        return $this->release->tracker($key) ?? self::fail(\sprintf('No tracker "%s".', $key));
    }

    private function rolled(string $table, int $total): StepResult
    {
        return StepResult::table($this->release->resolveOracleTable($table, new ScriptedRandomNumberGenerator($total)));
    }

    /**
     * Ends the current scene, skipping the steps still waiting before it.
     *
     * @param list<string> $skipping
     */
    private function endScene(Campaign $campaign, array $skipping = []): void
    {
        foreach ($skipping as $key) {
            $campaign->skipFlowStep($key, $this->release, self::at());
        }

        $campaign->endFlowScene($campaign->currentScene()?->number() ?? self::fail('No scene to end.'), $this->release, self::at());
    }

    /**
     * The world turn of Act 1 on a quiet street (a 2 on the die).
     */
    private function quietStreet(Campaign $campaign): void
    {
        $campaign->completeFlowStep('street', self::roll('1d6', 2), $this->release, self::at());
    }

    private function createYourCharacter(Campaign $campaign): void
    {
        $campaign->addCharacter(CharacterId::fromString('vi'), 'Vi', [], [], ['role' => 'Netrunner'], $this->release);
        $campaign->completeFlowStep('you', StepResult::character('vi'), $this->release, self::at());
    }

    private function recruit(Campaign $campaign, string $name, string $specialty): void
    {
        $campaign->pickSceneType('recruit', $this->release, self::at());
        $campaign->completeFlowStep('who', self::prompt($name), $this->release, self::at());
        $campaign->completeFlowStep('specialty', StepResult::choice($specialty), $this->release, self::at());
        $this->endScene($campaign);
    }

    /**
     * Picks every candidate the current pick step offers.
     */
    private function pickAll(Campaign $campaign): StepResult
    {
        return StepResult::pick(ItemKind::Npc, array_map(static fn (PickCandidate $candidate): PickedItem => new PickedItem($candidate->id, $candidate->name), $this->candidates($campaign)));
    }

    /**
     * @return list<PickCandidate>
     */
    private function candidates(Campaign $campaign): array
    {
        return ($campaign->flowRunView($this->release)?->step->pick ?? self::fail('The step offers no pick.'))->candidates;
    }

    /**
     * Street zero done with a fixer, Mama Lux (or none), at Act 1's scene pick. Vi has 500 eb.
     */
    private function actOne(bool $withFixer = true): Campaign
    {
        $campaign = $this->guidedCampaign();
        $campaign->startSession(self::at(), $this->release);
        $campaign->pickSceneType('edgerunner', $this->release, self::at());
        $this->createYourCharacter($campaign);
        $this->endScene($campaign);
        $campaign->pickSceneType('turf', $this->release, self::at());
        $campaign->completeFlowStep('block', self::prompt('Neon Row'), $this->release, self::at());
        $campaign->completeFlowStep('crew-name', self::prompt('The Static'), $this->release, self::at());
        $this->endScene($campaign);
        $campaign->pickSceneType('fixer', $this->release, self::at());
        if ($withFixer) {
            $campaign->completeFlowStep('fixer', self::prompt('Mama Lux'), $this->release, self::at());
            $this->endScene($campaign);
        } else {
            $this->endScene($campaign, skipping: ['fixer']);
        }

        return $campaign;
    }

    /**
     * Act 1 left by the player ("Move on") and the big job named: waiting at Act 2's scene pick.
     */
    private function inPlanning(): Campaign
    {
        $campaign = $this->actOne();
        $campaign->moveOn('making-a-name', $this->release, self::at());
        $campaign->completeFlowStep('big-job', self::prompt('The Arasaka vault'), $this->release, self::at());

        return $campaign;
    }

    /**
     * Housing 0 and the Month clock full: Vi waits at the rent of the cube hotel (500 eb).
     */
    private function atMonthsEnd(): Campaign
    {
        $campaign = $this->actOne();
        $campaign->setTrackerValue($this->tracker('month'), 3, self::at());
        $campaign->pickSceneType('downtime', $this->release, self::at());
        $this->endScene($campaign, skipping: ['spend']);
        $campaign->completeFlowStep('days', self::prompt('Lying low'), $this->release, self::at());
        $campaign->completeFlowStep('market', StepResult::choice('no'), $this->release, self::at());
        $this->quietStreet($campaign);
        $campaign->pickSceneType('months-end', $this->release, self::at());

        return $campaign;
    }

    /**
     * Act 1 left by the player ("Move on"), the big job and the fallout skipped through, waiting at the betrayal's first pick.
     * Mr. Kline, a client, comes from a gig offer.
     */
    private function atTheFinale(bool $withFixer): Campaign
    {
        $campaign = $this->actOne($withFixer);
        $campaign->pickSceneType('gig-offer', $this->release, self::at());
        $campaign->completeFlowStep('client', self::prompt('Mr. Kline'), $this->release, self::at());
        $this->endScene($campaign, skipping: ['gig']);
        $campaign->completeFlowStep('take-it', StepResult::choice('no'), $this->release, self::at());
        $this->quietStreet($campaign);

        $campaign->moveOn('making-a-name', $this->release, self::at());
        $campaign->completeFlowStep('big-job', self::prompt('The Arasaka vault'), $this->release, self::at());
        $campaign->moveOn('planning', $this->release, self::at());
        $campaign->moveOn('the-job', $this->release, self::at());
        $campaign->pickSceneType('getaway', $this->release, self::at());
        $this->endScene($campaign, skipping: ['slip-out']);
        $campaign->pickSceneType('fallout', $this->release, self::at());
        $this->endScene($campaign);
        $campaign->skipFlowStep('who-knows', $this->release, self::at());
        $campaign->pickSceneType('betrayal', $this->release, self::at());

        return $campaign;
    }
}
