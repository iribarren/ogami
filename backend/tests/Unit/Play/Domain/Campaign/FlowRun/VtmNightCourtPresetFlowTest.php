<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shipped `vtm-night-court` preset played through the Campaign: Hunger pools of Vi (a 6 dice
 * Brawl pool with 3 Hunger dice) that end in a messy critical or a bestial failure, Rouse the Blood at
 * Dawn, and the world turn that rolls 1d10 under the Masquerade to send the Second Inquisition.
 * The dice are scripted in the order they are rolled, so the Hunger dice are the last three faces.
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(Campaign::class)]
final class VtmNightCourtPresetFlowTest extends FlowRunTestCase
{
    /**
     * A legal Character: 22 attribute points, 14 skill points, Hunger 3.
     */
    private const array VI = [
        'strength' => 3, 'dexterity' => 3, 'stamina' => 3, 'charisma' => 3, 'manipulation' => 3, 'composure' => 2, 'intelligence' => 2, 'wits' => 2, 'resolve' => 1,
        'brawl' => 3, 'athletics' => 3, 'stealth' => 3, 'streetwise' => 2, 'persuasion' => 3,
        'larceny' => 0, 'awareness' => 0, 'insight' => 0, 'intimidation' => 0, 'subterfuge' => 0, 'investigation' => 0, 'occult' => 0,
        'hunger' => 3,
    ];

    private const string ENTRY = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9001';

    private GameSystemSnapshot $release;

    protected function setUp(): void
    {
        /** @var array<string, mixed> $content */
        $content = json_decode((string) file_get_contents(__DIR__.'/../../../../../../presets/vtm-night-court.json'), true, flags: \JSON_THROW_ON_ERROR);
        $this->release = self::translate($content);
    }

    #[Test]
    public function aMessyCriticalOnTheHuntFeedsAndExposes(): void
    {
        $campaign = $this->inTheHunt();

        // A pair of tens with a Hunger die among them: the messy critical has its own Outcome.
        $this->perform($campaign, 'brawl', 10, 2, 2, 2, 2, 10);
        $campaign->resolvePendingFlowCheck('check-1', null, $this->release, self::at());

        self::assertSame([1, 1], [$this->sheet($campaign, 'hunger'), $this->tracker($campaign, 'masquerade')]);
        self::assertSame(['open', null], self::position($campaign));
    }

    #[Test]
    public function aPlainFailureOnTheHuntOnlyExposes(): void
    {
        $campaign = $this->inTheHunt();

        $this->perform($campaign, 'brawl', 2, 2, 2, 2, 2, 2);
        $campaign->resolvePendingFlowCheck('check-1', null, $this->release, self::at());

        self::assertSame([3, 1], [$this->sheet($campaign, 'hunger'), $this->tracker($campaign, 'masquerade')]);
    }

    #[Test]
    public function aBestialFailureOnTheHuntReachesTheCompulsion(): void
    {
        $campaign = $this->inTheHunt();

        // Nothing succeeds and a Hunger die shows 1.
        $this->perform($campaign, 'brawl', 2, 2, 2, 2, 2, 1);
        $campaign->resolvePendingFlowCheck('check-1', null, $this->release, self::at());

        self::assertSame(['play', 'compulsion'], self::position($campaign));
        self::assertSame(0, $this->tracker($campaign, 'masquerade'));
    }

    #[Test]
    public function aFailedRouseAtDawnRaisesHungerByOne(): void
    {
        $campaign = $this->inTheChronicle();
        $campaign->pickSceneType('dawn', $this->release, self::at());

        $this->perform($campaign, 'rouse', 3);

        self::assertSame(4, $this->sheet($campaign, 'hunger'));
    }

    #[Test]
    public function aSucceededRouseLeavesHungerAlone(): void
    {
        $campaign = $this->inTheChronicle();
        $campaign->pickSceneType('dawn', $this->release, self::at());

        $this->perform($campaign, 'rouse', 8);

        self::assertSame(3, $this->sheet($campaign, 'hunger'));
    }

    #[Test]
    public function dawnAddsANight(): void
    {
        $campaign = $this->inTheChronicle();
        $campaign->pickSceneType('dawn', $this->release, self::at());
        $this->perform($campaign, 'rouse', 8);
        $campaign->endFlowScene(5, $this->release, self::at());
        self::assertSame(1, $this->tracker($campaign, 'night'));

        $campaign->completeFlowStep('prompt-1', self::prompt('In the cellar, dreaming of the sun'), $this->release, self::at());

        self::assertSame(2, $this->tracker($campaign, 'night'));
    }

    #[Test]
    public function aMasqueradeRollUnderItsValueForcesTheInquisitionRaid(): void
    {
        $campaign = $this->afterAHunt(masquerade: 2);

        $campaign->completeFlowStep('inquisition', self::roll('1d10', 2), $this->release, self::at());
        // The raid is queued: the world turn goes on, and a quiet night (1 to 3) ends it.
        self::assertSame('stir', self::position($campaign)[1]);
        $campaign->completeFlowStep('stir', self::roll('1d6', 1), $this->release, self::at());

        self::assertSame('inquisition-raid', self::flowRunOf($campaign)->forcedNextSceneType());
        $campaign->pickSceneType('inquisition-raid', $this->release, self::at());
        self::assertSame(['setup', 'prompt-1'], self::position($campaign));
    }

    #[Test]
    public function aMasqueradeRollOverItsValueLeavesTheNightToStir(): void
    {
        $campaign = $this->afterAHunt(masquerade: 2);

        $campaign->completeFlowStep('inquisition', self::roll('1d10', 3), $this->release, self::at());

        self::assertSame(['stir'], [self::position($campaign)[1]]);
    }

    #[Test]
    public function theRaidClosingTakesThreeOffTheMasqueradeAndNeverBelowZero(): void
    {
        $campaign = $this->afterAHunt(masquerade: 2);
        $campaign->completeFlowStep('inquisition', self::roll('1d10', 1), $this->release, self::at());
        $campaign->completeFlowStep('stir', self::roll('1d6', 1), $this->release, self::at());
        $campaign->pickSceneType('inquisition-raid', $this->release, self::at());
        $campaign->completeFlowStep('prompt-1', self::prompt('The Elysium annex'), $this->release, self::at());
        $this->perform($campaign, 'stealth', 6, 6, 6, 6, 6, 6);
        $campaign->endFlowScene(7, $this->release, self::at());

        $campaign->completeFlowStep('prompt-1', self::prompt('The city whispers'), $this->release, self::at());

        // The add -3 on a Masquerade of 2 is clamped at the tracker's minimum.
        self::assertSame(0, $this->tracker($campaign, 'masquerade'));
    }

    #[Test]
    public function theRaidClosingTakesThreeOffAMasqueradeAboveThree(): void
    {
        $campaign = $this->afterAHunt(masquerade: 9);
        $campaign->completeFlowStep('inquisition', self::roll('1d10', 1), $this->release, self::at());
        $campaign->completeFlowStep('stir', self::roll('1d6', 1), $this->release, self::at());
        $campaign->pickSceneType('inquisition-raid', $this->release, self::at());
        $campaign->completeFlowStep('prompt-1', self::prompt('The Elysium annex'), $this->release, self::at());
        $this->perform($campaign, 'stealth', 6, 6, 6, 6, 6, 6);
        $campaign->endFlowScene(7, $this->release, self::at());

        $campaign->completeFlowStep('prompt-1', self::prompt('The city whispers'), $this->release, self::at());

        self::assertSame(6, $this->tracker($campaign, 'masquerade'));
    }

    /**
     * Vi rolls the check at the current check step, picking it from the step's set (Dawn's step has Rouse alone): the
     * faces are in the order the dice are rolled. A roll that waits for Keep or Push is left pending.
     */
    private function perform(Campaign $campaign, string $check, int ...$faces): void
    {
        $stepKey = self::position($campaign)[1] ?? self::fail('Not at a step.');
        $campaign->flowCheckDifficulty($stepKey, 'vi', null, $this->release, self::at());
        $vi = $campaign->character(CharacterId::fromString('vi')) ?? self::fail('No Vi.');
        $values = SheetValues::of($this->release->sheet(), $vi->values(), $campaign->trackerValuesOf($this->release));
        $result = ($this->release->check($check) ?? self::fail('No check.'))->perform(new ScriptedRandomNumberGenerator(...$faces), $values, null, 0);

        $campaign->completeFlowStep($stepKey, StepResult::check('vi', $result, 'rouse' === $check ? null : $check), $this->release, self::at(), entryId: self::ENTRY);
    }

    private function sheet(Campaign $campaign, string $field): int
    {
        $values = ($campaign->character(CharacterId::fromString('vi')) ?? self::fail('No Vi.'))->values();

        return (int) ($values[$field] ?? self::fail('No such field.'));
    }

    private function tracker(Campaign $campaign, string $key): int
    {
        return $campaign->trackerValue($this->release->tracker($key) ?? self::fail('No such tracker.'));
    }

    /**
     * A campaign at the Hunt's check, past the Session Zero and the Hunt's setup.
     */
    private function inTheHunt(): Campaign
    {
        $campaign = $this->inTheChronicle();
        $campaign->pickSceneType('hunt', $this->release, self::at());
        $campaign->completeFlowStep('table-1', StepResult::table($this->release->resolveOracleTable('hunting-grounds', new ScriptedRandomNumberGenerator(1))), $this->release, self::at());
        $campaign->completeFlowStep('prompt-1', self::prompt('A vessel'), $this->release, self::at());
        self::assertSame(['play', 'check-1'], self::position($campaign));

        return $campaign;
    }

    /**
     * A campaign at the world turn's Inquisition roll, after a Hunt that did not roll Hunger dice into trouble.
     */
    private function afterAHunt(int $masquerade): Campaign
    {
        $campaign = $this->inTheHunt();
        $this->perform($campaign, 'brawl', 7, 7, 7, 7, 7, 7);
        $campaign->setTrackerValue($this->release->tracker('masquerade') ?? self::fail('No masquerade.'), $masquerade, self::at());
        $campaign->endFlowScene(5, $this->release, self::at());
        self::assertSame('inquisition', self::position($campaign)[1]);

        return $campaign;
    }

    /**
     * A campaign through the four Session Zero scenes, at the chronicle's scene pick.
     */
    private function inTheChronicle(): Campaign
    {
        $campaign = self::guided($this->release, 'chronicle');
        $campaign->startSession(self::at(), $this->release);
        $campaign->addCharacter(CharacterId::fromString('vi'), 'Vi', [], [], self::VI, $this->release);
        foreach ([['city', 3], ['court', 2], ['factions', 2]] as $number => [$scene, $prompts]) {
            $campaign->pickSceneType($scene, $this->release, self::at());
            for ($prompt = 1; $prompt <= $prompts; ++$prompt) {
                $campaign->completeFlowStep('prompt-'.$prompt, self::prompt('Text '.$prompt), $this->release, self::at());
            }
            $campaign->endFlowScene($number + 1, $this->release, self::at());
        }

        $campaign->pickSceneType('character', $this->release, self::at());
        $campaign->completeFlowStep('character-1', StepResult::character('vi'), $this->release, self::at());
        for ($prompt = 1; $prompt <= 3; ++$prompt) {
            $campaign->completeFlowStep('prompt-'.$prompt, self::prompt('Text '.$prompt), $this->release, self::at());
        }
        $campaign->endFlowScene(4, $this->release, self::at());

        return $campaign;
    }
}
