<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignEffects;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\CreationMethod;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\PendingCheck;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\GameSystem\Check\CheckPush;
use App\Play\Domain\GameSystem\Check\CheckResult;
use App\Play\Domain\GameSystem\Check\PoolResult;
use App\Play\Domain\GameSystem\Flow\SheetEffect;
use App\Play\Domain\GameSystem\Flow\SheetOperation;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shipped `lanterns-of-the-mire` preset played through the Campaign: the Inquiry check that
 * adds a Clue or lets the Nightfall in, a full Nightfall that ends the Investigation, the Condition
 * a pushed roll marks, and the Respite that clears one. The investigator has Reason 3 and Lore and
 * Inquiry at 3, so both rolls are pools of six d6 where a six succeeds. The prologue's Inquiry scene
 * is scene 2 (the opening is scene 1) and the Investigation's first scene is scene 3.
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(Campaign::class)]
final class LanternsOfTheMirePresetFlowTest extends FlowRunTestCase
{
    private const string ENTRY = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f8001';

    private const array SKILLS = ['brawl', 'endure', 'haul', 'shoot', 'sneak', 'nimble', 'lore', 'inquiry', 'alertness', 'persuade', 'mend', 'insight'];

    private GameSystemSnapshot $release;

    /** @var array<string, mixed> */
    private array $values = [];

    protected function setUp(): void
    {
        /** @var array<string, mixed> $content */
        $content = json_decode((string) file_get_contents(__DIR__.'/../../../../../../presets/lanterns-of-the-mire.json'), true, flags: \JSON_THROW_ON_ERROR);
        $this->release = self::translate($content);
    }

    #[Test]
    public function aStrongInquiryAddsAClueAndLeavesTheNight(): void
    {
        $campaign = $this->inTheFirstInquiry();

        $campaign->completeFlowStep('check-1', $this->rolled('inquiry', 6, 6, 6, 6, 6, 6), $this->release, self::at());

        self::assertSame([1, 0], $this->clues($campaign));
    }

    #[Test]
    public function aKeptSuccessAddsAClue(): void
    {
        $campaign = $this->inTheFirstInquiry();

        $campaign->completeFlowStep('check-1', $this->rolled('inquiry', 6, 1, 2, 3, 4, 5), $this->release, self::at(), entryId: self::ENTRY);
        self::assertSame([0, 0], $this->clues($campaign));
        $campaign->resolvePendingFlowCheck('check-1', null, $this->release, self::at());

        self::assertSame([1, 0], $this->clues($campaign));
    }

    #[Test]
    public function aKeptFailureDrawsTheNightIn(): void
    {
        $campaign = $this->inTheFirstInquiry();

        $campaign->completeFlowStep('check-1', $this->rolled('inquiry', 1, 2, 3, 4, 5, 1), $this->release, self::at(), entryId: self::ENTRY);
        $campaign->resolvePendingFlowCheck('check-1', null, $this->release, self::at());

        self::assertSame([0, 1], $this->clues($campaign));
    }

    #[Test]
    public function aPushedCheckMarksTheConditionThePlayerPicksAndTheNewBandAppliesItsEffects(): void
    {
        $campaign = $this->inTheFirstInquiry();
        $result = $this->performed('lore', 1, 2, 3, 4, 5, 1);
        $campaign->completeFlowStep('check-1', StepResult::check('inv', $result, 'lore'), $this->release, self::at(), entryId: self::ENTRY);
        self::assertInstanceOf(PendingCheck::class, self::flowRunOf($campaign)->pendingCheck());

        // What PushCheckHandler does for a step's roll: re-roll the failed dice, pay the cost with the player's pick, resolve on the new band.
        $check = $this->release->check('lore') ?? self::fail('No lore.');
        self::assertInstanceOf(CheckPush::class, $check->push);
        self::assertInstanceOf(PoolResult::class, $result->pool);
        $pushed = $check->push(new ScriptedRandomNumberGenerator(6, 6, 6, 6, 6, 6), SheetValues::of($this->release->sheet(), $this->values, []), $result->pool->roll, null);
        $effects = new CampaignEffects($campaign, $this->release);
        self::assertSame(['rattled', 'dread', 'despair'], $effects->unmarkedOptions('mind', 'inv'));
        foreach ($check->push->effects as $effect) {
            self::assertInstanceOf(SheetEffect::class, $effect);
            $effects->applySheetEffect($effect, 'inv', 'dread');
        }
        $campaign->resolvePendingFlowCheck('check-1', $pushed->band->key, $this->release, self::at());

        self::assertSame(['strong', ['dread'], [1, 0]], [$pushed->band->key, $this->marked($campaign, 'mind'), $this->clues($campaign)]);
        self::assertSame([], $this->marked($campaign, 'body'));
    }

    #[Test]
    public function respiteClearsTheConditionThePlayerChoosesAndTheNightDrawsIn(): void
    {
        $campaign = $this->inTheInvestigation();
        $this->markCondition($campaign, 'mind', 'dread');
        $this->markCondition($campaign, 'body', 'bruised');
        $campaign->pickSceneType('respite', $this->release, self::at());
        $campaign->completeFlowStep('prompt-1', self::prompt('The vicarage'), $this->release, self::at());

        $campaign->completeFlowStep('choice-1', StepResult::choice('steady-nerves', 'inv'), $this->release, self::at());

        self::assertSame([[], ['bruised'], [1, 1]], [$this->marked($campaign, 'mind'), $this->marked($campaign, 'body'), $this->clues($campaign)]);
    }

    /**
     * @return iterable<string, array{int, int}> the Nightfall when the scene ends and the phase the Flow is in
     */
    public static function nightfalls(): iterable
    {
        yield 'five segments: the night still holds' => [5, 1];
        yield 'six segments: night has fallen, on to the unmasking' => [6, 2];
    }

    #[Test]
    #[DataProvider('nightfalls')]
    public function aFullNightfallAtTheEndOfAnInvestigationSceneEndsThePhase(int $nightfall, int $phase): void
    {
        $campaign = $this->inTheInvestigation();
        $campaign->pickSceneType('inquiry', $this->release, self::at());
        $campaign->completeFlowStep('prompt-1', self::prompt('The mill'), $this->release, self::at());
        $campaign->completeFlowStep('table-1', self::table('a-clue', 'A ring'), $this->release, self::at());
        $campaign->setTrackerValue($this->release->tracker('nightfall') ?? self::fail('No nightfall.'), $nightfall, self::at());
        $campaign->completeFlowStep('check-1', $this->rolled('inquiry', 6, 6, 6, 6, 6, 6), $this->release, self::at());

        $campaign->endFlowScene(3, $this->release, self::at());

        self::assertSame($phase, self::flowRunOf($campaign)->phaseIndex());
        if (2 === $phase) {
            // The Unmasking opens with the Confrontation's first step.
            self::assertSame(['setup', 'table-1'], self::position($campaign));
        }
    }

    private function performed(string $check, int ...$faces): CheckResult
    {
        return ($this->release->check($check) ?? self::fail('No check.'))->perform(new ScriptedRandomNumberGenerator(...$faces), SheetValues::of($this->release->sheet(), $this->values, []), null, 0);
    }

    private function rolled(string $check, int ...$faces): StepResult
    {
        return StepResult::check('inv', $this->performed($check, ...$faces), $check);
    }

    /**
     * @return array{int, int} Clues and Nightfall
     */
    private function clues(Campaign $campaign): array
    {
        return [$campaign->trackerValue($this->release->tracker('clues') ?? self::fail('No clues.')), $campaign->trackerValue($this->release->tracker('nightfall') ?? self::fail('No nightfall.'))];
    }

    /**
     * @return list<string> the marked options of a Conditions field of the investigator
     */
    private function marked(Campaign $campaign, string $field): array
    {
        $value = $campaign->character(CharacterId::fromString('inv'))?->values()[$field] ?? [];
        \assert(\is_array($value));

        return array_values($value);
    }

    private function markCondition(Campaign $campaign, string $field, string $option): void
    {
        new CampaignEffects($campaign, $this->release)->applySheetEffect(new SheetEffect($field, SheetOperation::Mark, $option), 'inv');
    }

    /**
     * The prologue done: its Inquiry scene played with a strong success and ended, at the Investigation's scene pick.
     */
    private function inTheInvestigation(): Campaign
    {
        $campaign = $this->inTheFirstInquiry();
        $campaign->completeFlowStep('check-1', $this->rolled('inquiry', 6, 6, 6, 6, 6, 6), $this->release, self::at());
        $campaign->endFlowScene(2, $this->release, self::at());

        return $campaign;
    }

    /**
     * The prologue's Inquiry scene, at its check.
     */
    private function inTheFirstInquiry(): Campaign
    {
        $campaign = $this->inThePrologue();
        $campaign->completeFlowStep('prompt-1', self::prompt('The chapel'), $this->release, self::at());
        $campaign->completeFlowStep('table-1', self::table('a-clue', 'A ring'), $this->release, self::at());

        return $campaign;
    }

    private function inThePrologue(): Campaign
    {
        $campaign = self::guided($this->release, 'a-mystery');
        $campaign->startSession(self::at(), $this->release);
        $this->values = [
            'vigour' => 3, 'finesse' => 3, 'reason' => 3, 'heart' => 3,
            ...array_fill_keys(self::SKILLS, 0),
            'brawl' => 2, 'lore' => 3, 'inquiry' => 3,
        ];
        $campaign->addCharacter(CharacterId::fromString('inv'), 'Inv', [], [], $this->values, $this->release, CreationMethod::Points);
        $campaign->completeFlowStep('character-1', StepResult::character('inv'), $this->release, self::at());
        $campaign->completeFlowStep('table-1', self::table('where-the-trouble-lies', 'A drowned chapel'), $this->release, self::at());
        $campaign->completeFlowStep('prompt-1', self::prompt('Edda Voss'), $this->release, self::at());
        $campaign->completeFlowStep('prompt-2', self::prompt('Lights over the water'), $this->release, self::at());

        return $campaign;
    }
}
