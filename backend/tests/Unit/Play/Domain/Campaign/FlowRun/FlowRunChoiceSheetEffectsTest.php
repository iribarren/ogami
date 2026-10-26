<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\InvalidStepResult;
use App\Play\Domain\Campaign\FlowRun\SheetChange;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Sheet effects on a choice option and a prompt step (ADR 0042), on valid/v2-sheet-effects with the
 * Setup Scene Type's play part swapped for the choice "learned" (option "yes" adds 5 Power, "no" is
 * the skip, "maybe" changes nothing), the prompt "cost" (loses 2 hit points) and the plain prompt
 * "note". Ida (Power 60) and Bo (Power 40) are in the Setup scene.
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(StepResult::class)]
final class FlowRunChoiceSheetEffectsTest extends FlowRunTestCase
{
    private GameSystemSnapshot $release;

    protected function setUp(): void
    {
        $this->release = self::translate($this->content());
    }

    #[Test]
    public function aPickedOptionChangesTheSheetOfTheCharacterTheResultNames(): void
    {
        $campaign = $this->campaign();

        $changes = $campaign->completeFlowStep('learned', StepResult::choice('yes', 'bo'), $this->release, self::at());

        self::assertEquals([new SheetChange('pow', 40, 45)], $changes);
        self::assertSame([60, 45, ['play', 'cost']], [$this->power($campaign, 'ida'), $this->power($campaign, 'bo'), self::position($campaign)]);
    }

    #[Test]
    public function aPromptChangesTheSheetOfTheCharacterTheResultNames(): void
    {
        $campaign = $this->atTheCost();

        $changes = $campaign->completeFlowStep('cost', StepResult::prompt(' It hurt ', 'ida'), $this->release, self::at());

        self::assertEquals([new SheetChange('hp', 12, 10)], $changes);
        self::assertSame([['current' => 10, 'max' => 12], ['current' => 12, 'max' => 12], 'It hurt'], [$this->value($campaign, 'ida', 'hp'), $this->value($campaign, 'bo', 'hp'), self::flowRunOf($campaign)->answers()['cost']]);
    }

    #[Test]
    public function anOptionAndAPromptWithoutSheetEffectsChangeNoSheet(): void
    {
        $campaign = $this->campaign();

        $picked = $campaign->completeFlowStep('learned', StepResult::choice('maybe'), $this->release, self::at());
        $campaign->skipFlowStep('cost', $this->release, self::at());
        $answered = $campaign->completeFlowStep('note', StepResult::prompt('Fine'), $this->release, self::at());

        self::assertSame([[], [], [60, 40]], [$picked, $answered, [$this->power($campaign, 'ida'), $this->power($campaign, 'bo')]]);
    }

    #[Test]
    public function aSkipAppliesNoSheetEffect(): void
    {
        $campaign = $this->campaign();

        $campaign->skipFlowStep('learned', $this->release, self::at());
        $campaign->skipFlowStep('cost', $this->release, self::at());

        self::assertSame([['play', 'note'], [60, 40], ['current' => 12, 'max' => 12]], [self::position($campaign), [$this->power($campaign, 'ida'), $this->power($campaign, 'bo')], $this->value($campaign, 'ida', 'hp')]);
    }

    /**
     * @return iterable<string, array{string, StepResult, string}> the step, the result and the message
     */
    public static function refusedResults(): iterable
    {
        yield 'an option with sheet effects and no Character' => ['learned', StepResult::choice('yes'), 'Step "learned" is a choice step: it needs "characterId".'];
        yield 'an option with sheet effects and an unknown Character' => ['learned', StepResult::choice('yes', 'ghost'), 'Choice step "learned" has no Character "ghost" in the campaign.'];
        yield 'an option without sheet effects and a Character' => ['learned', StepResult::choice('maybe', 'ida'), 'Step "learned" is a choice step: it takes no "characterId".'];
        yield 'a prompt with sheet effects and no Character' => ['cost', StepResult::prompt('Ouch'), 'Step "cost" is a prompt step: it needs "characterId".'];
        yield 'a prompt with sheet effects and an unknown Character' => ['cost', StepResult::prompt('Ouch', 'ghost'), 'Prompt step "cost" has no Character "ghost" in the campaign.'];
        yield 'a prompt without sheet effects and a Character' => ['note', StepResult::prompt('Fine', 'ida'), 'Step "note" is a prompt step: it takes no "characterId".'];
    }

    #[Test]
    #[DataProvider('refusedResults')]
    public function aResultThatNamesTheCharacterWrongChangesNothing(string $stepKey, StepResult $result, string $message): void
    {
        $campaign = 'learned' === $stepKey ? $this->campaign() : $this->atTheCost();
        if ('note' === $stepKey) {
            $campaign->skipFlowStep('cost', $this->release, self::at());
        }

        $before = clone $campaign;

        try {
            $campaign->completeFlowStep($stepKey, $result, $this->release, self::at());
            self::fail('The step was completed.');
        } catch (InvalidStepResult $e) {
            self::assertSame($message, $e->getMessage());
        }

        self::assertEquals($before, $campaign);
    }

    /**
     * Ida (Power 60) and Bo (Power 40) in the Setup scene, at the choice.
     */
    private function campaign(): Campaign
    {
        $campaign = self::guided($this->release, 'play');
        $campaign->startSession(self::at(), $this->release);
        $campaign->addCharacter(CharacterId::fromString('ida'), 'Ida', [], [], ['pow' => 60], $this->release);
        $campaign->addCharacter(CharacterId::fromString('bo'), 'Bo', [], [], ['pow' => 40], $this->release);
        $campaign->editCurrentSceneCast([], [CharacterId::fromString('ida'), CharacterId::fromString('bo')]);
        self::assertSame(['play', 'learned'], self::position($campaign));

        return $campaign;
    }

    private function atTheCost(): Campaign
    {
        $campaign = $this->campaign();
        $campaign->completeFlowStep('learned', StepResult::choice('maybe'), $this->release, self::at());

        return $campaign;
    }

    private function power(Campaign $campaign, string $character): mixed
    {
        return $this->value($campaign, $character, 'pow');
    }

    private function value(Campaign $campaign, string $character, string $field): mixed
    {
        return $campaign->character(CharacterId::fromString($character))?->values()[$field] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function content(): array
    {
        $sheet = static fn (string $field, string $op, int $value): array => ['kind' => 'sheet', 'field' => $field, 'op' => $op, 'value' => $value];
        $content = ReleaseViews::fixtureContent('valid/v2-sheet-effects');
        $content = ReleaseArrays::with($content, 'flows', [['key' => 'play', 'name' => 'Play', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => [], 'phases' => [
            ['key' => 'prologue', 'name' => 'Prologue', 'mode' => 'once', 'selection' => ['rule' => 'player', 'sceneTypes' => ['setup']]],
        ]]]);
        $content = ReleaseArrays::with($content, 'sceneTypes.0.setup', []);

        /** @var array<string, mixed> $translated */
        $translated = ReleaseArrays::with($content, 'sceneTypes.0.play', [
            ['key' => 'learned', 'kind' => 'choice', 'title' => 'Did you learn something?', 'skip' => 'no', 'options' => [
                ['key' => 'yes', 'label' => 'Yes', 'effects' => [$sheet('pow', 'add', 5)]],
                ['key' => 'no', 'label' => 'No'],
                ['key' => 'maybe', 'label' => 'Maybe'],
            ]],
            ['key' => 'cost', 'kind' => 'prompt', 'title' => 'What did it cost you?', 'effects' => [$sheet('hp', 'subtract', 2)]],
            ['key' => 'note', 'kind' => 'prompt', 'title' => 'A note'],
        ]);

        return $translated;
    }
}
