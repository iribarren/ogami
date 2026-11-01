<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignEffects;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\CreationMethod;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\PickedItem;
use App\Play\Domain\Campaign\FlowRun\ShopLine;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\Campaign\InsufficientFunds;
use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Play\Domain\GameSystem\Flow\NextSceneEffect;
use App\Play\Domain\GameSystem\Flow\TrackerEffect;
use App\Play\Domain\GameSystem\Flow\TrackerOperation;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shipped `west-marches` preset played through the Campaign: the founding, the session opening
 * that picks an expedition and a party, travel with the world turn's encounters by distance, an
 * abstract fight to victory that the world turn never interrupts, a trap and a poisoned needle, a
 * lair, a rest chain, the town's shops and the stranded closing. Ada (Strength 15, 11 hit points,
 * Armor Class 15), Bo and Cy are the roster; the session after the founding is session 2.
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(Campaign::class)]
final class WestMarchesPresetFlowTest extends FlowRunTestCase
{
    private GameSystemSnapshot $release;

    protected function setUp(): void
    {
        /** @var array<string, mixed> $content */
        $content = json_decode((string) file_get_contents(__DIR__.'/../../../../../../presets/west-marches.json'), true, flags: \JSON_THROW_ON_ERROR);
        $this->release = self::translate($content);
    }

    #[Test]
    public function foundingMakesTheTownAPlaceTwoRumorsAndTheRoster(): void
    {
        $campaign = $this->founded();

        $place = $campaign->places()[0];
        self::assertSame(['Saltmarsh', ['town']], [$place->name(), $place->tags()]);
        self::assertSame('Saltmarsh', $campaign->factValues($this->release)['town']);
        self::assertSame(['A bell ringing under the lake', 'Wolves that walk upright near the ford'], array_map(static fn (\App\Play\Domain\Campaign\Lists\Thread $thread): string => $thread->name(), $campaign->threads()));
        self::assertSame([['Old Maud', ['patron']]], array_map(static fn (\App\Play\Domain\Campaign\Lists\Npc $npc): array => [$npc->name(), $npc->tags()], $campaign->npcs()));
        self::assertCount(3, $campaign->characters());
        self::assertSame(1, self::flowRunOf($campaign)->phaseIndex());
    }

    #[Test]
    public function aSessionWithAnOpenThreadPicksItAsTheExpeditionAndThePartyGoesOut(): void
    {
        $campaign = $this->founded();
        $campaign->endSession(self::at(), null, $this->release);
        $this->endClosing($campaign);
        $this->setTracker($campaign, 'foe', 1);
        $campaign->startSession(self::at(), $this->release);

        $thread = $campaign->threads()[0];
        $campaign->completeFlowStep('expedition', StepResult::pick(ItemKind::Thread, [new PickedItem($thread->id()->toString(), $thread->name())]), $this->release, self::at());
        self::assertSame([null, 'party'], self::position($campaign), 'A found thread skips the new rumor.');
        $campaign->completeFlowStep('party', StepResult::pick(ItemKind::Character, [new PickedItem('ada', 'Ada'), new PickedItem('bo', 'Bo')]), $this->release, self::at());

        self::assertSame('A bell ringing under the lake', $campaign->factValues($this->release)['expedition']);
        self::assertSame([[null, null], 0, 0], [self::position($campaign), $this->tracker($campaign, 'foe'), $this->tracker($campaign, 'away')], 'Heading out clears a foe left over.');
        self::assertSame(['ada', 'bo'], array_values(array_map(static fn (CharacterId $id): string => $id->toString(), array_filter($campaign->currentSession()?->party() ?? [], static fn (CharacterId $id): bool => 'cy' !== $id->toString()))));
    }

    #[Test]
    public function aSessionWithNoOpenThreadRollsARumorThatBecomesTheExpedition(): void
    {
        $campaign = $this->founded(rumors: false);
        $campaign->endSession(self::at(), null, $this->release);
        $this->endClosing($campaign);
        $campaign->startSession(self::at(), $this->release);

        $campaign->completeFlowStep('expedition', StepResult::pick(ItemKind::Thread, []), $this->release, self::at());
        self::assertSame([null, 'new-rumor'], self::position($campaign));
        $campaign->completeFlowStep('new-rumor', $this->rolled('rumors', 4), $this->release, self::at());
        self::assertSame([null, 'party'], self::position($campaign));

        self::assertSame(['Gold in the old mine', 'Gold in the old mine'], [$campaign->threads()[0]->name(), $campaign->factValues($this->release)['expedition']]);
    }

    /**
     * @return iterable<string, array{int, int, string}> the distance before the leg, the world turn die and the step it reaches
     */
    public static function encountersByDistance(): iterable
    {
        yield 'a leg out of town is near' => [0, 1, 'near'];
        yield 'the second leg is in the hills' => [1, 1, 'mid'];
        yield 'the third leg is still in the hills' => [2, 1, 'mid'];
        yield 'the fourth leg is deep in the wilds' => [3, 1, 'deep'];
    }

    #[Test]
    #[DataProvider('encountersByDistance')]
    public function aLegOfTravelUsesARationAndTheWorldTurnRollsTheTableOfTheDistance(int $before, int $die, string $step): void
    {
        $campaign = $this->expedition();
        $this->setTracker($campaign, 'away', $before);

        $this->travel($campaign);
        self::assertSame([$before + 1, 4], [$this->tracker($campaign, 'away'), $this->tracker($campaign, 'rations')]);
        $campaign->completeFlowStep('wander', self::roll('1d6', $die), $this->release, self::at());

        self::assertSame([null, $step], self::position($campaign));
    }

    #[Test]
    public function aQuietWorldTurnLeavesTheRoadOpenAndTownIsNeverVisited(): void
    {
        $campaign = $this->expedition();
        $this->travel($campaign);
        $campaign->completeFlowStep('wander', self::roll('1d6', 2), $this->release, self::at());
        self::assertSame([null, null], self::position($campaign));

        $campaign->pickSceneType('return', $this->release, self::at());
        $campaign->completeFlowStep('bring', self::prompt('A map and a limp'), $this->release, self::at());
        $this->endScene($campaign);

        self::assertSame([0, null, [null, null]], [$this->tracker($campaign, 'away'), self::flowRunOf($campaign)->forcedNextSceneType(), self::position($campaign)], 'In town the world turn rolls nothing.');
    }

    #[Test]
    public function withNoRationsLeftSomeoneGoesHungry(): void
    {
        $campaign = $this->expedition();
        $this->setTracker($campaign, 'rations', 0);

        $campaign->pickSceneType('travel', $this->release, self::at());
        $campaign->completeFlowStep('march', self::prompt('North'), $this->release, self::at());
        $this->endScene($campaign, skipping: ['look']);
        self::assertSame(['closing', 'hungry'], self::position($campaign));
        $campaign->completeFlowStep('hungry', StepResult::prompt('Cy gives up a share', 'cy'), $this->release, self::at());

        self::assertSame([1, 0], [$this->character($campaign, 'cy')['exhaustion'], $this->character($campaign, 'ada')['exhaustion']]);
    }

    #[Test]
    public function everyEncounterEntryThatForcesACombatSetsTheFoeFirst(): void
    {
        $forced = 0;
        foreach (['encounters-near', 'encounters-mid', 'encounters-deep'] as $table) {
            foreach (range(1, 8) as $total) {
                $entry = $this->release->rolledEntry($this->release->resolveOracleTable($table, new ScriptedRandomNumberGenerator($total))->steps()[0]);
                self::assertNotNull($entry);
                $effects = $entry->effects;
                if (array_any($effects, static fn (object $effect): bool => $effect instanceof NextSceneEffect && 'combat' === $effect->sceneType)) {
                    ++$forced;
                    self::assertEquals(new TrackerEffect('foe', TrackerOperation::Set, 1), $effects[0], \sprintf('%s entry %d', $table, $total));
                }
            }
        }

        self::assertSame(15, $forced);
    }

    #[Test]
    public function aFightRunsToVictoryAndNoWanderingMonsterReplacesAnyLinkOfTheChain(): void
    {
        // Giant rats near town: the entry sets the foe and forces the combat.
        $campaign = $this->encounter(0, 'near', 'encounters-near', 4);
        self::assertSame(['combat', 1], [self::flowRunOf($campaign)->forcedNextSceneType(), $this->tracker($campaign, 'foe')]);

        // The combat names the foe, sets threat 1 and 2 hits near town, and the world turn is guarded while foe stands.
        $campaign->pickSceneType('combat', $this->release, self::at());
        $campaign->completeFlowStep('name', self::prompt('Giant Rat'), $this->release, self::at());
        self::assertSame(['Giant Rat', 'Giant Rat', [1, 2]], [$campaign->factValues($this->release)['foe'], array_last($campaign->npcs())?->name(), [$this->tracker($campaign, 'threat'), $this->tracker($campaign, 'foe')]]);
        $this->endScene($campaign, skipping: ['begin']);
        self::assertSame(['combat-round', [null, null]], [self::flowRunOf($campaign)->forcedNextSceneType(), self::position($campaign)]);

        // Round one: a hit leaves one; the rat's bite (a 15 against Armor Class 15) costs threat 1 x 4 hit points; the choice fights on.
        $campaign->pickSceneType('combat-round', $this->release, self::at());
        $campaign->completeFlowStep('attack', $this->check($campaign, 'ada', 'melee', 15), $this->release, self::at());
        self::assertSame(1, $this->tracker($campaign, 'foe'));
        $campaign->completeFlowStep('defend', $this->check($campaign, 'ada', 'defend', 15), $this->release, self::at());
        self::assertSame(7, $this->hp($campaign, 'ada'));
        $campaign->skipFlowStep('death-save', $this->release, self::at());
        $campaign->completeFlowStep('slot', StepResult::choice('none'), $this->release, self::at());
        $this->endScene($campaign, skipping: ['potion']);
        $campaign->completeFlowStep('again', StepResult::choice('fight'), $this->release, self::at());
        self::assertSame('combat-round', self::flowRunOf($campaign)->forcedNextSceneType());

        // Round two: the last hit fells it. The world turn still stands down (a 1 on its die would have meant an encounter).
        $campaign->pickSceneType('combat-round', $this->release, self::at());
        $campaign->completeFlowStep('attack', $this->check($campaign, 'ada', 'melee', 20), $this->release, self::at());
        $this->endScene($campaign, skipping: ['slot', 'potion']);
        self::assertSame(['victory', 0, 1, [null, null]], [self::flowRunOf($campaign)->forcedNextSceneType(), $this->tracker($campaign, 'foe'), $this->tracker($campaign, 'threat'), self::position($campaign)]);

        // Victory pays threat 1: 10 gold and 100 XP to Ada, then settles the fight so the guard reopens.
        $campaign->pickSceneType('victory', $this->release, self::at());
        $campaign->completeFlowStep('loot', $this->rolled('spoils', 6), $this->release, self::at());
        $campaign->completeFlowStep('spoils', StepResult::prompt('Ada takes them', 'ada'), $this->release, self::at());
        self::assertSame([60, 100], [$this->character($campaign, 'ada')['gp'], $this->character($campaign, 'ada')['xp']]);
        $this->endScene($campaign);
        self::assertSame([0, 0, [null, 'wander']], [$this->tracker($campaign, 'foe'), $this->tracker($campaign, 'threat'), self::position($campaign)], 'The guard is open again: the world turn rolls.');
    }

    #[Test]
    public function fleeingSettlesTheFightAndStopsTheChain(): void
    {
        $campaign = $this->encounter(3, 'deep', 'encounters-deep', 4);
        $campaign->pickSceneType('combat', $this->release, self::at());
        $campaign->completeFlowStep('name', self::prompt('Troll'), $this->release, self::at());
        self::assertSame([3, 5], [$this->tracker($campaign, 'threat'), $this->tracker($campaign, 'foe')]);
        $this->endScene($campaign, skipping: ['begin']);
        $campaign->pickSceneType('combat-round', $this->release, self::at());
        $campaign->skipFlowStep('attack', $this->release, self::at());
        $campaign->completeFlowStep('defend', $this->check($campaign, 'ada', 'defend', 1), $this->release, self::at());
        $campaign->skipFlowStep('death-save', $this->release, self::at());
        self::assertSame(11, $this->hp($campaign, 'ada'), 'A miss costs nothing.');
        $this->endScene($campaign, skipping: ['slot', 'potion']);

        $campaign->completeFlowStep('again', StepResult::choice('flee'), $this->release, self::at());

        self::assertSame([0, 0, null], [$this->tracker($campaign, 'foe'), $this->tracker($campaign, 'threat'), self::flowRunOf($campaign)->forcedNextSceneType()]);
    }

    #[Test]
    public function aTrapCostsHitPointsAndAPoisonedNeedleMarksTheCondition(): void
    {
        $campaign = $this->expedition();
        $campaign->pickSceneType('delve', $this->release, self::at());
        $campaign->completeFlowStep('room', $this->rolled('rooms', 4), $this->release, self::at());
        self::assertSame(['setup', 'trap'], self::position($campaign));
        // Dexterity 14 gives +2: a 5 makes 7 against DC 13. 2d6 of 3 and 4 cost 7 hit points.
        $campaign->completeFlowStep('trap', $this->checkAgainst(13, $campaign, 'ada', 'dex', 5), $this->release, self::at(), new ScriptedRandomNumberGenerator(3, 4));
        self::assertSame([4, 1, ['play', 'explore']], [$this->hp($campaign, 'ada'), $this->tracker($campaign, 'torches'), self::position($campaign)]);

        // A success costs nothing and does not fall through into the needle.
        $safe = $this->expedition();
        $safe->pickSceneType('delve', $this->release, self::at());
        $safe->completeFlowStep('room', $this->rolled('rooms', 4), $this->release, self::at());
        $safe->completeFlowStep('trap', $this->checkAgainst(13, $safe, 'ada', 'dex', 15), $this->release, self::at());
        self::assertSame([11, ['play', 'explore']], [$this->hp($safe, 'ada'), self::position($safe)]);

        $campaign = $this->expedition();
        $campaign->pickSceneType('delve', $this->release, self::at());
        $campaign->completeFlowStep('room', $this->rolled('rooms', 5), $this->release, self::at());
        $campaign->completeFlowStep('needle', $this->checkAgainst(12, $campaign, 'bo', 'con', 1), $this->release, self::at(), new ScriptedRandomNumberGenerator(2));
        self::assertSame([9, ['poisoned']], [$this->hp($campaign, 'bo'), $this->character($campaign, 'bo')['conditions']]);
    }

    #[Test]
    public function aSafeRoomBurnsATorchAndAFullClockPromptsTheLastTorch(): void
    {
        $campaign = $this->expedition();
        $this->setTracker($campaign, 'torches', 5);

        $campaign->pickSceneType('delve', $this->release, self::at());
        $campaign->completeFlowStep('room', $this->rolled('rooms', 1), $this->release, self::at());

        self::assertSame([6, ['setup', 'gutters']], [$this->tracker($campaign, 'torches'), self::position($campaign)]);
    }

    #[Test]
    public function aLairForcesTheFightAndTheOnwardChoiceNeverReplacesIt(): void
    {
        $campaign = $this->expedition();
        $campaign->pickSceneType('delve', $this->release, self::at());
        $campaign->completeFlowStep('room', $this->rolled('rooms', 6), $this->release, self::at());
        self::assertSame([1, 'combat', [null, 'explore'] === self::position($campaign)], [$this->tracker($campaign, 'foe'), self::flowRunOf($campaign)->forcedNextSceneType(), false]);
        $campaign->skipFlowStep('explore', $this->release, self::at());

        $campaign->endFlowScene($campaign->currentScene()?->number() ?? self::fail('No scene.'), $this->release, self::at());

        self::assertSame(['combat', [null, null]], [self::flowRunOf($campaign)->forcedNextSceneType(), self::position($campaign)], 'No "Next room, or leave?" and no wandering monster.');
    }

    #[Test]
    public function aQuietRoomOffersNextRoomOrLeaveAndEachChainsItsScene(): void
    {
        foreach (['next' => 'delve', 'leave' => 'travel'] as $option => $scene) {
            $campaign = $this->expedition();
            $campaign->pickSceneType('delve', $this->release, self::at());
            $campaign->completeFlowStep('room', $this->rolled('rooms', 2), $this->release, self::at());
            $this->endScene($campaign, skipping: ['explore']);

            $campaign->completeFlowStep('onward', StepResult::choice($option), $this->release, self::at());

            self::assertSame($scene, self::flowRunOf($campaign)->forcedNextSceneType());
        }
    }

    #[Test]
    public function exploringANewSiteCreatesAPlaceTaggedByDistanceAndAKnownSiteEndsTheSetup(): void
    {
        $campaign = $this->expedition();
        $this->setTracker($campaign, 'away', 2);
        $campaign->pickSceneType('explore-site', $this->release, self::at());
        $campaign->completeFlowStep('known', StepResult::pick(ItemKind::Place, []), $this->release, self::at());
        $campaign->completeFlowStep('feature', $this->rolled('site-features', 5), $this->release, self::at());
        $campaign->completeFlowStep('name', self::prompt('Ashwatch'), $this->release, self::at());

        $site = array_find($campaign->places(), static fn (\App\Play\Domain\Campaign\Lists\Place $place): bool => 'Ashwatch' === $place->name());
        self::assertSame(['site', 'mid'], $site?->tags());
        self::assertSame(['play', 'test'], self::position($campaign), 'The generic ability test waits in open play.');

        $revisit = $this->expedition();
        $revisit->addPlace(\App\Play\Domain\Campaign\Lists\PlaceId::fromString('p1'), 'Ashwatch', null, ['site', 'mid'], [], 1, $this->release);
        $revisit->pickSceneType('explore-site', $this->release, self::at());
        $places = \count($revisit->places());
        $revisit->completeFlowStep('known', StepResult::pick(ItemKind::Place, [new PickedItem('p1', 'Ashwatch')]), $this->release, self::at());
        self::assertSame([['play', 'test'], $places], [self::position($revisit), \count($revisit->places())], 'A known site asks for no name and makes no Place.');
    }

    #[Test]
    public function aGenericTestOffersTheModeAndRollsTheAbilityPickedWithIt(): void
    {
        $campaign = $this->expedition();
        $campaign->pickSceneType('explore-site', $this->release, self::at());
        $campaign->completeFlowStep('known', StepResult::pick(ItemKind::Place, []), $this->release, self::at());
        $campaign->skipFlowStep('feature', $this->release, self::at());
        $campaign->completeFlowStep('name', self::prompt('Ashwatch'), $this->release, self::at());

        $campaign->completeFlowStep('test', StepResult::choice('advantage'), $this->release, self::at());
        self::assertSame(['play', 'test-adv'], self::position($campaign));
        $campaign->completeFlowStep('test-adv', $this->check($campaign, 'ada', 'str-adv', 3, 17), $this->release, self::at());

        self::assertSame(['open', null], self::position($campaign), 'The check is the last step of the test: back to open play.');
    }

    #[Test]
    public function stockingUpSpendsGoldForRationsAndTorchesAndABrokeAdventurerCanSkipIt(): void
    {
        $campaign = $this->expedition();
        $this->setTracker($campaign, 'rations', 1);
        $this->setTracker($campaign, 'torches', 4);
        $campaign->pickSceneType('town', $this->release, self::at());
        $campaign->skipFlowStep('news', $this->release, self::at());
        $campaign->skipFlowStep('hire', $this->release, self::at());
        $campaign->skipFlowStep('level-up', $this->release, self::at());

        $campaign->completeFlowStep('stock-up', StepResult::choice('buy', 'ada'), $this->release, self::at(), new ScriptedRandomNumberGenerator(1, 2, 3, 4, 5, 6));
        self::assertSame([47, 6, 0], [$this->character($campaign, 'ada')['gp'], $this->tracker($campaign, 'rations'), $this->tracker($campaign, 'torches')]);

        $broke = $this->expedition();
        new CampaignEffects($broke, $this->release)->applySheetEffect(new \App\Play\Domain\GameSystem\Flow\SheetEffect('gp', \App\Play\Domain\GameSystem\Flow\SheetOperation::Set, 0), 'ada');
        $broke->pickSceneType('town', $this->release, self::at());
        $broke->skipFlowStep('news', $this->release, self::at());
        $broke->skipFlowStep('hire', $this->release, self::at());
        $broke->skipFlowStep('level-up', $this->release, self::at());
        try {
            $broke->completeFlowStep('stock-up', StepResult::choice('buy', 'ada'), $this->release, self::at(), new ScriptedRandomNumberGenerator(1, 2, 3));
            self::fail('A broke adventurer cannot pay.');
        } catch (InsufficientFunds $refused) {
            self::assertStringContainsString('Not enough "gp"', $refused->getMessage());
        }

        $broke->skipFlowStep('stock-up', $this->release, self::at(), new ScriptedRandomNumberGenerator(1, 1, 1, 1));
        self::assertSame(['outfitter'], [self::position($broke)[1]]);
    }

    #[Test]
    public function theOutfitterSellsGearForGoldAndTheInnTakesOneGoldAndChainsTheRest(): void
    {
        $campaign = $this->expedition();
        $campaign->pickSceneType('town', $this->release, self::at());
        $campaign->skipFlowStep('news', $this->release, self::at());
        $campaign->skipFlowStep('hire', $this->release, self::at());
        $campaign->skipFlowStep('level-up', $this->release, self::at());
        $campaign->skipFlowStep('stock-up', $this->release, self::at(), new ScriptedRandomNumberGenerator(1, 1, 1, 1));
        $onSale = array_map(static fn (\App\Play\Domain\GameSystem\GearEntry $entry): string => $entry->key, $campaign->activeFlowRunView($this->release)->step->shop->onSale ?? []);
        self::assertCount(3, $onSale);

        $campaign->completeFlowStep('outfitter', StepResult::shop('ada', [ShopLine::buy($onSale[0], 1)], []), $this->release, self::at(), new ScriptedRandomNumberGenerator(1, 1, 1, 1, 1, 1));
        self::assertSame([[['gear' => $onSale[0], 'quantity' => 1]], 50 - ($this->release->gearEntry($onSale[0]) ?? self::fail('No gear.'))->cost], [$this->character($campaign, 'ada')['pack'], $this->character($campaign, 'ada')['gp']]);
        $campaign->completeFlowStep('smith', StepResult::shop('ada', [], []), $this->release, self::at());
        $this->endScene($campaign);
        $campaign->completeFlowStep('inn', StepResult::choice('rest', 'bo'), $this->release, self::at());

        self::assertSame([49, 'rest'], [$this->character($campaign, 'bo')['gp'], self::flowRunOf($campaign)->forcedNextSceneType()]);
    }

    #[Test]
    public function campingChainsOneRestPerAdventurerUntilNobodyElseRests(): void
    {
        $campaign = $this->expedition();
        $this->setTracker($campaign, 'away', 1);
        $effects = new CampaignEffects($campaign, $this->release);
        $effects->applySheetEffect(new \App\Play\Domain\GameSystem\Flow\SheetEffect('hp', \App\Play\Domain\GameSystem\Flow\SheetOperation::Subtract, 9), 'ada');
        $effects->applySheetEffect(new \App\Play\Domain\GameSystem\Flow\SheetEffect('hp', \App\Play\Domain\GameSystem\Flow\SheetOperation::Subtract, 9), 'bo');
        $effects->applySheetEffect(new \App\Play\Domain\GameSystem\Flow\SheetEffect('exhaustion', \App\Play\Domain\GameSystem\Flow\SheetOperation::Set, 2), 'ada');
        $effects->applySheetEffect(new \App\Play\Domain\GameSystem\Flow\SheetEffect('conditions', \App\Play\Domain\GameSystem\Flow\SheetOperation::Mark, 'poisoned'), 'ada');

        $campaign->pickSceneType('camp', $this->release, self::at());
        $campaign->completeFlowStep('fire', self::prompt('By the ford'), $this->release, self::at());
        $this->endScene($campaign);
        self::assertSame([4, 'rest'], [$this->tracker($campaign, 'rations'), self::flowRunOf($campaign)->forcedNextSceneType()]);
        $campaign->completeFlowStep('wander', self::roll('1d6', 6), $this->release, self::at());

        // A long rest for Ada: full hit points, a level of exhaustion gone, her poison cleared by "Who recovers?".
        $campaign->pickSceneType('rest', $this->release, self::at());
        $campaign->completeFlowStep('rest', StepResult::choice('long', 'ada'), $this->release, self::at());
        $campaign->completeFlowStep('recover', StepResult::choice('yes', 'ada'), $this->release, self::at());
        $this->endScene($campaign);
        self::assertSame([11, 1, []], [$this->hp($campaign, 'ada'), $this->character($campaign, 'ada')['exhaustion'], $this->character($campaign, 'ada')['conditions']]);
        $campaign->completeFlowStep('another', StepResult::choice('yes'), $this->release, self::at());
        $campaign->completeFlowStep('wander', self::roll('1d6', 6), $this->release, self::at());

        // A short rest for Bo spends the hit die (the SRD's average, 10/2 + 1 + 1 = 7) and the chain stops at "No".
        $campaign->pickSceneType('rest', $this->release, self::at());
        $campaign->completeFlowStep('rest', StepResult::choice('short', 'bo'), $this->release, self::at());
        $this->endScene($campaign, skipping: ['recover']);
        self::assertSame([9, 0], [$this->hp($campaign, 'bo'), $this->track($campaign, 'bo', 'hit-dice')]);
        $campaign->completeFlowStep('another', StepResult::choice('no'), $this->release, self::at());
        self::assertNull(self::flowRunOf($campaign)->forcedNextSceneType());
    }

    #[Test]
    public function aSessionEndedFarFromTownRollsTheStrandedTableAndClosesTheResolvedThread(): void
    {
        $campaign = $this->expedition();
        $this->setTracker($campaign, 'away', 3);

        $campaign->endSession(self::at(), null, $this->release);
        self::assertSame([null, 'stranded'], self::position($campaign));
        $campaign->completeFlowStep('stranded', $this->rolled('stranded', 1), $this->release, self::at());
        self::assertSame(4, $this->tracker($campaign, 'rations'), 'A cold night spoils a ration.');
        $campaign->completeFlowStep('resolved', StepResult::choice('yes'), $this->release, self::at());
        $campaign->skipFlowStep('new-rumor', $this->release, self::at());

        $bell = array_find($campaign->threads(), static fn (\App\Play\Domain\Campaign\Lists\Thread $thread): bool => 'A bell ringing under the lake' === $thread->name());
        $wolves = array_find($campaign->threads(), static fn (\App\Play\Domain\Campaign\Lists\Thread $thread): bool => str_starts_with($thread->name(), 'Wolves'));
        self::assertSame([false, true], [$bell?->isOpen(), $wolves?->isOpen()]);
    }

    private function founded(bool $rumors = true): Campaign
    {
        $campaign = self::guided($this->release, 'west-marches');
        $campaign->startSession(self::at(), $this->release);
        foreach (['ada', 'bo', 'cy'] as $id) {
            $campaign->addCharacter(CharacterId::fromString($id), ucfirst($id), [], [], ['str' => 15, 'dex' => 14, 'con' => 13, 'int' => 12, 'wis' => 10, 'cha' => 8, 'hit-die' => 10, 'ac' => 15], $this->release, CreationMethod::Points);
        }

        $campaign->pickSceneType('founding-town', $this->release, self::at());
        $campaign->completeFlowStep('name', self::prompt('Saltmarsh'), $this->release, self::at());
        $campaign->skipFlowStep('feel', $this->release, self::at());
        foreach (['rumor-1' => [1, 'rumors'], 'rumor-2' => [7, 'rumors']] as $key => [$total]) {
            $rumors ? $campaign->completeFlowStep($key, $this->rolled('rumors', 1 === $total ? 7 : 2), $this->release, self::at()) : $campaign->skipFlowStep($key, $this->release, self::at());
        }

        $this->endScene($campaign);
        $campaign->pickSceneType('roster', $this->release, self::at());
        foreach (['first' => 'ada', 'second' => 'bo', 'third' => 'cy'] as $step => $id) {
            $campaign->completeFlowStep($step, StepResult::character($id), $this->release, self::at());
        }

        $campaign->skipFlowStep('fourth', $this->release, self::at());
        $campaign->skipFlowStep('fifth', $this->release, self::at());
        $campaign->completeFlowStep('patron', self::prompt('Old Maud'), $this->release, self::at());
        $this->endScene($campaign);

        return $campaign;
    }

    /**
     * Session 2 opened with the first rumor and Ada, Bo and Cy as the party, waiting at the scene pick in town.
     */
    private function expedition(): Campaign
    {
        $campaign = $this->founded();
        $campaign->endSession(self::at(), null, $this->release);
        $this->endClosing($campaign);
        $campaign->startSession(self::at(), $this->release);
        $thread = $campaign->threads()[0];
        $campaign->completeFlowStep('expedition', StepResult::pick(ItemKind::Thread, [new PickedItem($thread->id()->toString(), $thread->name())]), $this->release, self::at());
        $campaign->completeFlowStep('party', StepResult::pick(ItemKind::Character, array_map(static fn (string $id): PickedItem => new PickedItem($id, ucfirst($id)), ['ada', 'bo', 'cy'])), $this->release, self::at());

        return $campaign;
    }

    /**
     * A leg of travel from the given distance, a 1 on the world turn's die and the encounter table's entry rolled: the entry's effects applied.
     */
    private function encounter(int $before, string $step, string $table, int $entry): Campaign
    {
        $campaign = $this->expedition();
        $this->setTracker($campaign, 'away', $before);
        $this->travel($campaign);
        $campaign->completeFlowStep('wander', self::roll('1d6', 1), $this->release, self::at());
        $campaign->completeFlowStep($step, $this->rolled($table, $entry), $this->release, self::at());

        return $campaign;
    }

    /**
     * The closing of the session that ended after the founding: in town, nothing to resolve.
     */
    private function endClosing(Campaign $campaign): void
    {
        $campaign->skipFlowStep('safe', $this->release, self::at());
        $campaign->skipFlowStep('resolved', $this->release, self::at());
        $campaign->skipFlowStep('new-rumor', $this->release, self::at());
    }

    /**
     * A leg of travel ended: away +1 and a ration eaten, waiting at the world turn's die.
     */
    private function travel(Campaign $campaign): void
    {
        $campaign->pickSceneType('travel', $this->release, self::at());
        $campaign->completeFlowStep('march', self::prompt('North'), $this->release, self::at());
        $this->endScene($campaign, skipping: ['look']);
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

    private function check(Campaign $campaign, string $who, string $check, int ...$faces): StepResult
    {
        return $this->checkAgainst(null, $campaign, $who, $check, ...$faces);
    }

    private function checkAgainst(?int $difficulty, Campaign $campaign, string $who, string $check, int ...$faces): StepResult
    {
        $values = $campaign->character(CharacterId::fromString($who))?->values() ?? self::fail('No character.');
        $result = ($this->release->check($check) ?? self::fail('No check.'))->perform(new ScriptedRandomNumberGenerator(...$faces), SheetValues::of($this->release->sheet(), $values, []), $difficulty, 0);

        return StepResult::check($who, $result, \in_array($check, ['defend', 'death-save'], true) ? null : $check);
    }

    private function tracker(Campaign $campaign, string $key): int
    {
        return $campaign->trackerValue($this->release->tracker($key) ?? self::fail('No tracker.'));
    }

    private function setTracker(Campaign $campaign, string $key, int $value): void
    {
        $campaign->setTrackerValue($this->release->tracker($key) ?? self::fail('No tracker.'), $value, self::at());
    }

    /**
     * @return array<string, mixed>
     */
    private function character(Campaign $campaign, string $id): array
    {
        return $campaign->character(CharacterId::fromString($id))?->values() ?? self::fail('No character.');
    }

    private function hp(Campaign $campaign, string $id): int
    {
        return $this->track($campaign, $id, 'hp');
    }

    private function track(Campaign $campaign, string $id, string $field): int
    {
        $track = $this->character($campaign, $id)[$field];
        \assert(\is_array($track) && \is_int($track['current']));

        return $track['current'];
    }
}
