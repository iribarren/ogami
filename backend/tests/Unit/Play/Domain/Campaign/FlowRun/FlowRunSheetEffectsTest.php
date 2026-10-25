<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\Campaign\FlowRun\FlowFormulaFailed;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunContext;
use App\Play\Domain\Campaign\FlowRun\SheetChange;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Sheet effects while a FlowRun plays (ADR 0027), on valid/v2-sheet-effects with a Flow "play" of
 * one phase: the character step "you" sets Sanity (track, max 99) to Power (number 1 to 99), then
 * the check step "shock" (d%, "pass" up to Sanity) changes the checked Character. Ida (Power 60) and
 * Bo (Power 40) are in the Setup scene; Ida completes "you".
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(FlowRunContext::class)]
#[CoversClass(SheetChange::class)]
final class FlowRunSheetEffectsTest extends FlowRunTestCase
{
    #[Test]
    public function aCharacterStepChangesTheSheetOfTheCharacterItCompletesWith(): void
    {
        $release = $this->sheetRelease();
        $campaign = $this->campaign($release);

        $changes = $campaign->completeFlowStep('you', StepResult::character('ida'), $release, self::at());

        self::assertEquals([new SheetChange('sanity', 99, 60)], $changes);
        self::assertSame([['current' => 60, 'max' => 99], ['current' => 99, 'max' => 99]], [$this->value($campaign, 'ida', 'sanity'), $this->value($campaign, 'bo', 'sanity')]);
    }

    #[Test]
    public function skippingACharacterStepAppliesNoneOfItsEffects(): void
    {
        $release = $this->sheetRelease(mandatory: false);
        $campaign = $this->campaign($release);

        $campaign->skipFlowStep('you', $release, self::at());

        // No Character completed the step, so no sheet changes and nothing throws.
        self::assertSame([['play', 'shock'], [['current' => 99, 'max' => 99], ['current' => 99, 'max' => 99]]], [self::position($campaign), [$this->value($campaign, 'ida', 'sanity'), $this->value($campaign, 'bo', 'sanity')]]);
    }

    #[Test]
    public function aCheckOutcomeChangesTheCheckedCharacterInListOrderAndRollsItsDice(): void
    {
        $release = $this->sheetRelease();
        $campaign = $this->atTheShock($release);

        // 90 is over Sanity 60: a fail. Sanity loses 1d6 (4), HP 1, Power gains its bonus 6 less Dread 0.
        $changes = $campaign->completeFlowStep('shock', $this->shocked($release, $campaign, 'ida', 90), $release, self::at(), new ScriptedRandomNumberGenerator(4));

        self::assertEquals([new SheetChange('sanity', 60, 56, '1d6', 4), new SheetChange('hp', 12, 11), new SheetChange('pow', 60, 66)], $changes);
        self::assertSame([56, 11, 66], [$this->value($campaign, 'ida', 'sanity')['current'] ?? null, $this->value($campaign, 'ida', 'hp')['current'] ?? null, $this->value($campaign, 'ida', 'pow')]);
        self::assertSame(['current' => 99, 'max' => 99], $this->value($campaign, 'bo', 'sanity'));
    }

    #[Test]
    public function theCheckedCharacterIsTheOneChangedNotTheOneOfTheCharacterStep(): void
    {
        $release = $this->sheetRelease(fail: [['kind' => 'sheet', 'field' => 'hp', 'op' => 'subtract', 'value' => 2]]);
        $campaign = $this->atTheShock($release);

        $campaign->completeFlowStep('shock', $this->shocked($release, $campaign, 'bo', 100), $release, self::at());

        self::assertSame([12, 10], [$this->value($campaign, 'ida', 'hp')['current'] ?? null, $this->value($campaign, 'bo', 'hp')['current'] ?? null]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, int, int}> the effect, the field, the value before and after
     */
    public static function clampedEffects(): iterable
    {
        $effect = static fn (string $field, string $op, mixed $value): array => ['kind' => 'sheet', 'field' => $field, 'op' => $op, 'value' => $value];

        yield 'a track does not fall under 0' => [$effect('hp', 'subtract', 20), 'hp', 12, 0];
        yield 'a track does not rise over its max' => [$effect('hp', 'add', 5), 'hp', 12, 12];
        yield 'set on a track sets its current value, clamped' => [$effect('sanity', 'set', 150), 'sanity', 60, 99];
        yield 'a number does not rise over its max' => [$effect('pow', 'add', 1000), 'pow', 60, 99];
        yield 'a number does not fall under its min' => [$effect('pow', 'subtract', 1000), 'pow', 60, 1];
        yield 'a number without bounds keeps the contract limit' => [$effect('luck', 'set', ['formula' => '{pow} * 100000']), 'luck', 0, 1_000_000];
        yield 'a formula reads the sheet' => [$effect('hp', 'set', ['formula' => '{hp.max} - {pow} / 10']), 'hp', 12, 6];
        yield 'a formula reads the value an earlier step left' => [$effect('pow', 'set', ['formula' => '{sanity} + {bonus}']), 'pow', 60, 66];
    }

    /**
     * @param array<string, mixed> $effect
     */
    #[Test]
    #[DataProvider('clampedEffects')]
    public function anEffectClampsToTheField(array $effect, string $field, int $before, int $after): void
    {
        $release = $this->sheetRelease(fail: [$effect]);
        $campaign = $this->atTheShock($release);

        $changes = $campaign->completeFlowStep('shock', $this->shocked($release, $campaign, 'ida', 90), $release, self::at());

        self::assertEquals([new SheetChange($field, $before, $after)], $changes);
        $value = $this->value($campaign, 'ida', $field);
        self::assertSame($after, \is_array($value) ? $value['current'] : $value);
    }

    #[Test]
    public function aFormulaReadsTheValueAnEarlierEffectOfTheListLeft(): void
    {
        $release = $this->sheetRelease(fail: [
            ['kind' => 'sheet', 'field' => 'sanity', 'op' => 'subtract', 'value' => 10],
            ['kind' => 'sheet', 'field' => 'pow', 'op' => 'set', 'value' => ['formula' => '{sanity}']],
        ]);
        $campaign = $this->atTheShock($release);

        $changes = $campaign->completeFlowStep('shock', $this->shocked($release, $campaign, 'ida', 90), $release, self::at());

        self::assertEquals([new SheetChange('sanity', 60, 50), new SheetChange('pow', 60, 50)], $changes);
    }

    #[Test]
    public function aFormulaThatFailsChangesNothing(): void
    {
        $release = $this->sheetRelease(fail: [
            ['kind' => 'sheet', 'field' => 'sanity', 'op' => 'subtract', 'value' => ['dice' => '1d6']],
            ['kind' => 'sheet', 'field' => 'pow', 'op' => 'set', 'value' => ['formula' => '{pow} / ({hp} - {hp})']],
        ]);
        $campaign = $this->atTheShock($release);
        $before = clone $campaign;

        try {
            $campaign->completeFlowStep('shock', $this->shocked($release, $campaign, 'ida', 90), $release, self::at(), new ScriptedRandomNumberGenerator(4));
            self::fail('The step was completed.');
        } catch (FlowFormulaFailed) {
        }

        self::assertEquals($before, $campaign);
    }

    #[Test]
    public function aStepWithoutSheetEffectsChangesNoSheet(): void
    {
        $release = $this->sheetRelease();
        $campaign = $this->atTheShock($release);

        // 10 is within Sanity 60: a pass, which loses 1 Sanity; the release without it changes nothing.
        $passed = $campaign->completeFlowStep('shock', $this->shocked($release, $campaign, 'ida', 10), $release, self::at());
        $plain = $this->sheetRelease(pass: []);
        $other = $this->atTheShock($plain);

        self::assertEquals([[new SheetChange('sanity', 60, 59)], []], [$passed, $other->completeFlowStep('shock', $this->shocked($plain, $other, 'ida', 10), $plain, self::at())]);
    }

    /**
     * @return iterable<string, array{list<array<string, mixed>>, list<string>, SheetChange, list<string>}> the outcome's effects, the marks before, the change and the marks after
     */
    public static function conditionEffects(): iterable
    {
        $effect = static fn (string $op, ?string $option = null): array => ['kind' => 'sheet', 'field' => 'wounds', 'op' => $op] + (null === $option ? [] : ['value' => $option]);

        yield 'mark adds the option' => [[$effect('mark', 'hurt')], [], new SheetChange('wounds', 0, 1), ['hurt']];
        yield 'mark keeps the declared order' => [[$effect('mark', 'hurt')], ['shaken'], new SheetChange('wounds', 1, 2), ['hurt', 'shaken']];
        yield 'mark of a marked option changes nothing' => [[$effect('mark', 'hurt')], ['hurt'], new SheetChange('wounds', 1, 1), ['hurt']];
        yield 'clear of an option removes it' => [[$effect('clear', 'hurt')], ['hurt', 'shaken'], new SheetChange('wounds', 2, 1), ['shaken']];
        yield 'clear of an unmarked option changes nothing' => [[$effect('clear', 'hurt')], ['shaken'], new SheetChange('wounds', 1, 1), ['shaken']];
        yield 'clear without an option empties the field' => [[$effect('clear')], ['hurt', 'shaken'], new SheetChange('wounds', 2, 0), []];
        yield 'clear of an empty field changes nothing' => [[$effect('clear')], [], new SheetChange('wounds', 0, 0), []];
    }

    /**
     * @param list<array<string, mixed>> $effects
     * @param list<string>               $marked
     * @param list<string>               $after
     */
    #[Test]
    #[DataProvider('conditionEffects')]
    public function markAndClearChangeTheMarkedOptionsOfAConditionsField(array $effects, array $marked, SheetChange $change, array $after): void
    {
        $release = $this->sheetRelease(fail: $effects, withConditions: true);
        $campaign = $this->atTheShock($release, ['wounds' => $marked]);

        $changes = $campaign->completeFlowStep('shock', $this->shocked($release, $campaign, 'ida', 90), $release, self::at());

        self::assertEquals([$change], $changes);
        self::assertSame($after, $this->value($campaign, 'ida', 'wounds'));
        self::assertSame([], $this->value($campaign, 'bo', 'wounds'), 'Only the checked Character changes.');
    }

    #[Test]
    public function aCharacterStoredBeforeTheFieldExistedIsMarkedFromNothing(): void
    {
        // Ida is created on a release without the field, as before the release that declares it.
        $old = $this->sheetRelease();
        $campaign = $this->atTheShock($old);
        self::assertArrayNotHasKey('wounds', $campaign->character(CharacterId::fromString('ida'))?->values() ?? []);
        $release = $this->sheetRelease(fail: [['kind' => 'sheet', 'field' => 'wounds', 'op' => 'mark', 'value' => 'shaken']], withConditions: true);

        $changes = $campaign->completeFlowStep('shock', $this->shocked($release, $campaign, 'ida', 90), $release, self::at());

        self::assertEquals([new SheetChange('wounds', 0, 1)], $changes);
        self::assertSame(['shaken'], $this->value($campaign, 'ida', 'wounds'));
    }

    #[Test]
    public function aMarkIsNotKeptWhenALaterEffectOfTheListFails(): void
    {
        $release = $this->sheetRelease(fail: [
            ['kind' => 'sheet', 'field' => 'wounds', 'op' => 'mark', 'value' => 'hurt'],
            ['kind' => 'sheet', 'field' => 'pow', 'op' => 'set', 'value' => ['formula' => '{pow} / ({hp} - {hp})']],
        ], withConditions: true);
        $campaign = $this->atTheShock($release);
        $before = clone $campaign;

        try {
            $campaign->completeFlowStep('shock', $this->shocked($release, $campaign, 'ida', 90), $release, self::at());
            self::fail('The step was completed.');
        } catch (FlowFormulaFailed) {
        }

        self::assertEquals($before, $campaign);
    }

    #[Test]
    public function aFormulaReadsTheCountMarkedAfterAnEarlierMark(): void
    {
        $release = $this->sheetRelease(fail: [
            ['kind' => 'sheet', 'field' => 'wounds', 'op' => 'mark', 'value' => 'hurt'],
            ['kind' => 'sheet', 'field' => 'pow', 'op' => 'set', 'value' => ['formula' => '{wounds} * 10']],
        ], withConditions: true);
        $campaign = $this->atTheShock($release, ['wounds' => ['shaken']]);

        $changes = $campaign->completeFlowStep('shock', $this->shocked($release, $campaign, 'ida', 90), $release, self::at());

        self::assertEquals([new SheetChange('wounds', 1, 2), new SheetChange('pow', 60, 20)], $changes);
    }

    /**
     * @param ?list<array<string, mixed>> $fail           the effects of the "fail" outcome, null for the fixture's
     * @param ?list<array<string, mixed>> $pass           the effects of the "pass" outcome, null for the fixture's
     * @param bool                        $mandatory      whether the character step "you" is mandatory
     * @param bool                        $withConditions whether the sheet also has the conditions field "wounds" (hurt, shaken)
     */
    private function sheetRelease(?array $fail = null, ?array $pass = null, bool $mandatory = true, bool $withConditions = false): GameSystemSnapshot
    {
        /** @var array{sheet: array{fields: list<array<string, mixed>>}} $content */
        $content = ReleaseViews::fixtureContent('valid/v2-sheet-effects');
        $extra = [['key' => 'luck', 'label' => 'Luck', 'type' => 'number']];
        if ($withConditions) {
            $extra[] = ['key' => 'wounds', 'label' => 'Wounds', 'type' => 'conditions', 'options' => [['key' => 'hurt', 'label' => 'Hurt'], ['key' => 'shaken', 'label' => 'Shaken']]];
        }

        $content = ReleaseArrays::with($content, 'sheet.fields', [...$content['sheet']['fields'], ...$extra]);
        $content = ReleaseArrays::with($content, 'flows', [['key' => 'play', 'name' => 'Play', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => ['dread'], 'phases' => [
            ['key' => 'prologue', 'name' => 'Prologue', 'mode' => 'once', 'selection' => ['rule' => 'player', 'sceneTypes' => ['setup']]],
        ]]]);
        $content = ReleaseArrays::with($content, 'sceneTypes.0.setup.0.mandatory', $mandatory);
        foreach (['0' => $fail, '1' => $pass] as $index => $effects) {
            if (null !== $effects) {
                $content = ReleaseArrays::with($content, 'sceneTypes.0.play.0.outcomes.'.$index.'.effects', $effects);
            }
        }

        /** @var array<string, mixed> $translated */
        $translated = $content;

        return self::translate($translated);
    }

    /**
     * Ida (Power 60) and Bo (Power 40) in the Setup scene, at the character step.
     *
     * @param array<string, mixed> $idaValues more values Ida starts with
     */
    private function campaign(GameSystemSnapshot $release, array $idaValues = []): Campaign
    {
        $campaign = self::guided($release, 'play');
        // The only Scene Type of the selection is picked at once.
        $campaign->startSession(self::at(), $release);
        $campaign->addCharacter(CharacterId::fromString('ida'), 'Ida', [], [], ['pow' => 60, ...$idaValues], $release);
        $campaign->addCharacter(CharacterId::fromString('bo'), 'Bo', [], [], ['pow' => 40], $release);
        $campaign->editCurrentSceneCast([], [CharacterId::fromString('ida'), CharacterId::fromString('bo')]);

        return $campaign;
    }

    /**
     * Ida completed "you" (Sanity 60), at the check step.
     *
     * @param array<string, mixed> $idaValues more values Ida starts with
     */
    private function atTheShock(GameSystemSnapshot $release, array $idaValues = []): Campaign
    {
        $campaign = $this->campaign($release, $idaValues);
        $campaign->completeFlowStep('you', StepResult::character('ida'), $release, self::at());
        self::assertSame(['play', 'shock'], self::position($campaign));

        return $campaign;
    }

    private function shocked(GameSystemSnapshot $release, Campaign $campaign, string $character, int $die): StepResult
    {
        $check = $release->check('sanity') ?? self::fail('No sanity check.');
        $values = $campaign->character(CharacterId::fromString($character))?->values() ?? [];

        return StepResult::check($character, $check->perform(new ScriptedRandomNumberGenerator($die), SheetValues::of($release->sheet(), $values, $campaign->trackerValuesOf($release)), null, 0));
    }

    /**
     * @return int|string|list<string>|array{current: int, max: int}|null
     */
    private function value(Campaign $campaign, string $character, string $field): int|string|array|null
    {
        return $campaign->character(CharacterId::fromString($character))?->values()[$field] ?? null;
    }
}
