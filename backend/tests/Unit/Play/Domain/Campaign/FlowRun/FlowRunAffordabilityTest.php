<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignEffects;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\SheetChange;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\Campaign\InsufficientFunds;
use App\Play\Domain\GameSystem\Flow\SheetEffect;
use App\Play\Domain\GameSystem\Flow\SheetOperation;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Affordability (ADR 0045), on valid/v2-sheet-effects with a number field "gold" as the currency
 * and a Flow "play" of one phase: the character step "you", then the choice "buy", the prompt "pay"
 * and the check step "shock". Ida has Power 60 and 10 gold; she completes "you".
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(CampaignEffects::class)]
#[CoversClass(InsufficientFunds::class)]
final class FlowRunAffordabilityTest extends FlowRunTestCase
{
    /**
     * @return iterable<string, array{string, int}> the option and the gold it would leave
     */
    public static function unaffordableOptions(): iterable
    {
        yield 'a subtract past the gold' => ['dear', -40];
        yield 'an add of a negative value' => ['negative', -1];
        yield 'a set under the floor' => ['reset', -1];
        yield 'a subtract whose formula resolves past the gold' => ['formula', -50];
        yield 'a subtract that lands under the floor by one' => ['deep', -20];
    }

    #[Test]
    #[DataProvider('unaffordableOptions')]
    public function aChoiceOptionThatTakesTheCurrencyUnderTheFloorIsRefusedAndChangesNothing(string $option, int $attempted): void
    {
        $release = $this->content();
        $campaign = $this->atTheBuy($release);

        $this->assertRefused($campaign, 'buy', StepResult::choice($option, 'ida'), $release, 10, $attempted, 0);
    }

    #[Test]
    public function anEffectEarlierInTheSameOptionDoesNotSurviveTheRefusal(): void
    {
        $release = $this->content();
        $campaign = $this->atTheBuy($release);

        $this->assertRefused($campaign, 'buy', StepResult::choice('both', 'ida'), $release, 10, -40, 0);
        self::assertSame(['current' => 12, 'max' => 12], $this->value($campaign, 'hp'));
    }

    /**
     * @return iterable<string, array{string, list<SheetChange>}>
     */
    public static function affordableOptions(): iterable
    {
        yield 'a subtract within the gold' => ['cheap', [new SheetChange('gold', 10, 5)]];
        yield 'a subtract down to exactly the floor' => ['exact', [new SheetChange('gold', 10, 0)]];
        yield 'an add before a subtract funds it' => ['fund', [new SheetChange('gold', 10, 30), new SheetChange('gold', 30, 5)]];
        yield 'a subtract on another field clamps as before' => ['wound', [new SheetChange('hp', 12, 0)]];
    }

    /**
     * @param list<SheetChange> $changes
     */
    #[Test]
    #[DataProvider('affordableOptions')]
    public function aChoiceOptionThatStaysAtOrOverTheFloorCompletes(string $option, array $changes): void
    {
        $release = $this->content();
        $campaign = $this->atTheBuy($release);

        self::assertEquals($changes, $campaign->completeFlowStep('buy', StepResult::choice($option, 'ida'), $release, self::at()));
        self::assertSame(['play', 'pay'], self::position($campaign));
    }

    #[Test]
    public function aPromptThatTakesTheCurrencyUnderTheFloorIsRefused(): void
    {
        $release = $this->content(pay: [$this->gold('subtract', 50)]);
        $campaign = $this->atTheBuy($release);
        $campaign->completeFlowStep('buy', StepResult::choice('free'), $release, self::at());

        $this->assertRefused($campaign, 'pay', StepResult::prompt('Paid', 'ida'), $release, 10, -40, 0);
    }

    #[Test]
    public function aCharacterStepThatTakesTheCurrencyUnderTheFloorIsRefused(): void
    {
        $release = $this->content(you: [$this->gold('subtract', 50)]);
        $campaign = $this->campaign($release);

        $this->assertRefused($campaign, 'you', StepResult::character('ida'), $release, 10, -40, 0);
    }

    #[Test]
    public function theFieldMinIsTheFloorWhenItIsSet(): void
    {
        $release = $this->content(min: -20);
        $campaign = $this->atTheBuy($release);

        $this->assertRefused($campaign, 'buy', StepResult::choice('dear', 'ida'), $release, 10, -40, -20);
        self::assertEquals([new SheetChange('gold', 10, -20)], $campaign->completeFlowStep('buy', StepResult::choice('deep', 'ida'), $release, self::at()));
    }

    #[Test]
    public function aCheckOutcomeStillClampsToTheField(): void
    {
        $release = $this->content(fail: [$this->gold('subtract', 50)], min: 0);
        $campaign = $this->atTheBuy($release);
        $campaign->completeFlowStep('buy', StepResult::choice('free'), $release, self::at());
        $campaign->skipFlowStep('pay', $release, self::at());

        $changes = $campaign->completeFlowStep('shock', $this->shocked($release, $campaign), $release, self::at());

        self::assertEquals([new SheetChange('gold', 10, 0)], $changes);
    }

    #[Test]
    public function aReleaseWithoutACurrencyClampsEveryChoiceOptionAsBefore(): void
    {
        $release = $this->content(min: 0, currency: false);
        $campaign = $this->atTheBuy($release);

        self::assertEquals([new SheetChange('gold', 10, 0)], $campaign->completeFlowStep('buy', StepResult::choice('dear', 'ida'), $release, self::at()));
    }

    #[Test]
    public function aPushCostKeepsClampingBecauseTheRefusalIsOptIn(): void
    {
        $release = $this->content(min: 0);
        $campaign = $this->atTheBuy($release);
        $effects = new CampaignEffects($campaign, $release);

        self::assertEquals(new SheetChange('gold', 10, 0), $effects->applySheetEffect(new SheetEffect('gold', SheetOperation::Subtract, 50), 'ida'));
        self::assertSame(0, $this->value($campaign, 'gold'));
    }

    /**
     * @return iterable<string, array{int, SheetOperation, int, int}> the gold before, the effect and the gold after
     */
    public static function spendsFromUnderTheFloor(): iterable
    {
        yield 'an add that leaves the gold still under the floor' => [-5, SheetOperation::Add, 2, -3];
        yield 'a set above the gold but still under the floor' => [-5, SheetOperation::Set, -3, -3];
        yield 'a set that keeps the gold where it is' => [-5, SheetOperation::Set, -5, -5];
        yield 'a subtract down to exactly the floor' => [3, SheetOperation::Subtract, 3, 0];
    }

    /**
     * A Character already under the floor (a check outcome took it there) may still gain money: only a
     * spend that leaves the currency under the floor and lower than it was is refused.
     */
    #[Test]
    #[DataProvider('spendsFromUnderTheFloor')]
    public function aVoluntaryEffectThatDoesNotLowerTheCurrencyUnderTheFloorIsApplied(int $gold, SheetOperation $op, int $value, int $after): void
    {
        $release = $this->content();
        $effects = new CampaignEffects($this->atTheBuy($release, $gold), $release);

        self::assertEquals(new SheetChange('gold', $gold, $after), $effects->applySheetEffect(new SheetEffect('gold', $op, $value), 'ida', refuseBelowFloor: true));
    }

    #[Test]
    public function aVoluntaryDecreaseFromUnderTheFloorIsRefused(): void
    {
        $release = $this->content();
        $campaign = $this->atTheBuy($release, -5);
        $effects = new CampaignEffects($campaign, $release);

        try {
            $effects->applySheetEffect(new SheetEffect('gold', SheetOperation::Subtract, 1), 'ida', refuseBelowFloor: true);
            self::fail('The spend was not refused.');
        } catch (InsufficientFunds $e) {
            self::assertSame(['gold', -5, -6, 0], [$e->field, $e->before, $e->attempted, $e->floor]);
        }

        self::assertSame(-5, $this->value($campaign, 'gold'));
    }

    #[Test]
    public function theExceptionTellsWhatTheSpendWouldHaveDone(): void
    {
        $release = $this->content();
        $effects = new CampaignEffects($this->atTheBuy($release), $release);

        try {
            $effects->applySheetEffect(new SheetEffect('gold', SheetOperation::Subtract, 50), 'ida', refuseBelowFloor: true);
            self::fail('The spend was not refused.');
        } catch (InsufficientFunds $e) {
            self::assertSame(['gold', 10, -40, 0], [$e->field, $e->before, $e->attempted, $e->floor]);
            self::assertSame('Not enough "gold": the step would take it from 10 to -40, under 0.', $e->getMessage());
        }
    }

    private function assertRefused(Campaign $campaign, string $stepKey, StepResult $result, GameSystemSnapshot $release, int $before, int $attempted, int $floor): void
    {
        $unchanged = clone $campaign;

        try {
            $campaign->completeFlowStep($stepKey, $result, $release, self::at());
            self::fail('The step was completed.');
        } catch (InsufficientFunds $e) {
            self::assertSame(['gold', $before, $attempted, $floor], [$e->field, $e->before, $e->attempted, $e->floor]);
        }

        self::assertEquals($unchanged, $campaign);
        self::assertSame($before, $this->value($campaign, 'gold'));
    }

    /**
     * @return array{kind: string, field: string, op: string, value: mixed}
     */
    private function gold(string $op, mixed $value, string $field = 'gold'): array
    {
        return ['kind' => 'sheet', 'field' => $field, 'op' => $op, 'value' => $value];
    }

    /**
     * @param list<array<string, mixed>> $you  the effects of the character step
     * @param list<array<string, mixed>> $pay  the effects of the prompt
     * @param list<array<string, mixed>> $fail the effects of the check's "fail" band
     */
    private function content(array $you = [], array $pay = [], array $fail = [], ?int $min = null, bool $currency = true): GameSystemSnapshot
    {
        /** @var array{sheet: array{fields: list<array<string, mixed>>}, sceneTypes: list<array{play: list<array<string, mixed>>}>} $content */
        $content = ReleaseViews::fixtureContent('valid/v2-sheet-effects');
        $check = ReleaseArrays::with($content['sceneTypes'][0]['play'][0], 'outcomes.0.effects', $fail);
        $content = ReleaseArrays::with($content, 'sheet.fields', [...$content['sheet']['fields'], ['key' => 'gold', 'label' => 'Gold', 'type' => 'number', ...(null === $min ? [] : ['min' => $min])]]);
        if ($currency) {
            $content = ReleaseArrays::with($content, 'sheet.currency', ['field' => 'gold', 'unit' => 'eb']);
        }

        $content = ReleaseArrays::with($content, 'flows', [['key' => 'play', 'name' => 'Play', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => ['dread'], 'phases' => [
            ['key' => 'prologue', 'name' => 'Prologue', 'mode' => 'once', 'selection' => ['rule' => 'player', 'sceneTypes' => ['setup']]],
        ]]]);
        $content = ReleaseArrays::with($content, 'sceneTypes.0.setup.0.effects', $you);
        $prompt = ['key' => 'pay', 'kind' => 'prompt', 'title' => 'What do you pay?', 'effects' => $pay];
        $buy = ['key' => 'buy', 'kind' => 'choice', 'title' => 'What do you buy?', 'options' => [
            ['key' => 'free', 'label' => 'Nothing'],
            ['key' => 'cheap', 'label' => 'Cheap', 'effects' => [$this->gold('subtract', 5)]],
            ['key' => 'exact', 'label' => 'Exact', 'effects' => [$this->gold('subtract', 10)]],
            ['key' => 'dear', 'label' => 'Dear', 'effects' => [$this->gold('subtract', 50)]],
            ['key' => 'deep', 'label' => 'Deep', 'effects' => [$this->gold('subtract', 30)]],
            ['key' => 'negative', 'label' => 'Negative', 'effects' => [$this->gold('add', -11)]],
            ['key' => 'reset', 'label' => 'Reset', 'effects' => [$this->gold('set', -1)]],
            ['key' => 'formula', 'label' => 'Formula', 'effects' => [$this->gold('subtract', ['formula' => '{pow}'])]],
            ['key' => 'fund', 'label' => 'Fund', 'effects' => [$this->gold('add', 20), $this->gold('subtract', 25)]],
            ['key' => 'both', 'label' => 'Both', 'effects' => [$this->gold('subtract', 2, 'hp'), $this->gold('subtract', 50)]],
            ['key' => 'wound', 'label' => 'Wound', 'effects' => [$this->gold('subtract', 50, 'hp')]],
        ]];

        /** @var array<string, mixed> $translated */
        $translated = ReleaseArrays::with($content, 'sceneTypes.0.play', [$buy, $prompt, $check]);

        return self::translate($translated);
    }

    /**
     * Ida (Power 60, 10 gold unless told otherwise) in the Setup scene, at the character step.
     */
    private function campaign(GameSystemSnapshot $release, int $gold = 10): Campaign
    {
        $campaign = self::guided($release, 'play');
        $campaign->startSession(self::at(), $release);
        $campaign->addCharacter(CharacterId::fromString('ida'), 'Ida', [], [], ['pow' => 60, 'gold' => $gold], $release);
        $campaign->editCurrentSceneCast([], [CharacterId::fromString('ida')]);

        return $campaign;
    }

    private function atTheBuy(GameSystemSnapshot $release, int $gold = 10): Campaign
    {
        $campaign = $this->campaign($release, $gold);
        $campaign->completeFlowStep('you', StepResult::character('ida'), $release, self::at());
        self::assertSame(['play', 'buy'], self::position($campaign));

        return $campaign;
    }

    private function shocked(GameSystemSnapshot $release, Campaign $campaign): StepResult
    {
        $check = $release->check('sanity') ?? self::fail('No sanity check.');
        $values = $campaign->character(CharacterId::fromString('ida'))?->values() ?? [];

        return StepResult::check('ida', $check->perform(new ScriptedRandomNumberGenerator(100), SheetValues::of($release->sheet(), $values, $campaign->trackerValuesOf($release)), null, 0));
    }

    private function value(Campaign $campaign, string $field): mixed
    {
        return $campaign->character(CharacterId::fromString('ida'))?->values()[$field] ?? null;
    }
}
