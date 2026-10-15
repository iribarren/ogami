<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Infrastructure\GameSystem;

use App\Formula\Domain\Formula;
use App\Play\Domain\GameSystem\Flow\CheckStep;
use App\Play\Domain\GameSystem\Flow\SheetEffect;
use App\Play\Domain\GameSystem\Flow\SheetOperation;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Infrastructure\GameSystem\GameSystemReleaseTranslator;
use App\Randomness\Domain\DiceExpression;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Play's anti-corruption layer reads sheet effects (ADR 0027) and re-checks what Studio checks on
 * publish: the field, the operation, the value and where the effect sits. The base release is
 * valid/v2-sheet-effects: the character step "you" sets Sanity to Power, the check step "shock"
 * changes Sanity, HP and Power on "fail" and Sanity on "pass".
 */
#[CoversClass(GameSystemReleaseTranslator::class)]
#[CoversClass(SheetEffect::class)]
final class GameSystemReleaseTranslatorSheetEffectsTest extends TestCase
{
    private const string UNREADABLE = 'GameSystem "sheet-effects" v1 cannot be read by Play: ';
    private const string FAIL_EFFECT = 'sceneTypes.0.play.0.outcomes.0.effects.0';

    #[Test]
    public function aCharacterStepAndACheckOutcomeHoldSheetEffects(): void
    {
        $sceneType = new GameSystemReleaseTranslator()->translate(ReleaseViews::of(ReleaseViews::fixtureContent('valid/v2-sheet-effects')))->sceneType('setup') ?? self::fail('No Scene Type "setup".');
        $shock = $sceneType->play->step('shock');
        \assert($shock instanceof CheckStep);

        self::assertEquals([new SheetEffect('sanity', SheetOperation::Set, Formula::fromString('{pow}'))], $sceneType->setup->step('you')?->effects);
        self::assertEquals([
            new SheetEffect('sanity', SheetOperation::Subtract, DiceExpression::fromString('1d6')),
            new SheetEffect('hp', SheetOperation::Subtract, 1),
            new SheetEffect('pow', SheetOperation::Add, Formula::fromString('{bonus} - {tracker:dread}')),
        ], $shock->outcomeFor('fail')?->effects);
    }

    #[Test]
    public function aFormulaReadsTrackCurrentAndMaxNumbersAndDerivedFields(): void
    {
        $content = ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-sheet-effects'), self::FAIL_EFFECT.'.value', ['formula' => '{hp.max} - {hp} + {pow} + {bonus} + {sanity}']);
        /** @var array<string, mixed> $content */
        $shock = new GameSystemReleaseTranslator()->translate(ReleaseViews::of($content))->sceneType('setup')?->play->step('shock');
        \assert($shock instanceof CheckStep);

        $effect = $shock->outcomeFor('fail')->effects[0] ?? null;
        \assert($effect instanceof SheetEffect);

        self::assertEquals(Formula::fromString('{hp.max} - {hp} + {pow} + {bonus} + {sanity}'), $effect->value);
    }

    /**
     * @return iterable<string, array{string, mixed, string}> the path changed, its new value and the expected message
     */
    public static function refusals(): iterable
    {
        $at = 'sceneTypes[0].play[0].outcomes[0].effects[0]';
        $sheet = ['kind' => 'sheet', 'field' => 'sanity', 'op' => 'subtract', 'value' => 1];
        $placement = 'a sheet effect is allowed only in the outcomes of a check step and in the effects of a character step.';

        yield 'an unknown field' => [self::FAIL_EFFECT.'.field', 'ghost', $at.'.field: unknown sheet field "ghost".'];
        yield 'a derived field' => [self::FAIL_EFFECT.'.field', 'bonus', $at.'.field: field "bonus" is a derived field, a sheet effect changes number and track fields only.'];
        yield 'an unknown operation' => [self::FAIL_EFFECT.'.op', 'multiply', $at.'.op: unknown sheet operation "multiply".'];
        yield 'a value out of range' => [self::FAIL_EFFECT.'.value', 1001, $at.'.value: must be between -1000 and 1000.'];
        yield 'a value of no form' => [self::FAIL_EFFECT.'.value', '1d6', $at.'.value: must be an integer, {"formula": string} or {"dice": string}.'];
        yield 'a value with a formula and dice' => [self::FAIL_EFFECT.'.value', ['formula' => '1', 'dice' => '1d6'], $at.'.value: must be an integer, {"formula": string} or {"dice": string}.'];
        yield 'a value that reads a tracker by reference' => [self::FAIL_EFFECT.'.value', ['tracker' => 'dread'], $at.'.value: must be an integer, {"formula": string} or {"dice": string}.'];
        yield 'bad dice' => [self::FAIL_EFFECT.'.value', ['dice' => 'banana'], $at.'.value.dice: invalid dice notation: '];
        yield 'dice too long' => [self::FAIL_EFFECT.'.value', ['dice' => str_repeat('1', 101)], $at.'.value.dice: must be at most 100 characters.'];
        yield 'a formula syntax error' => [self::FAIL_EFFECT.'.value', ['formula' => '1 +'], $at.'.value.formula: '];
        yield 'a formula that reads an unknown field' => [self::FAIL_EFFECT.'.value', ['formula' => '{nope}'], $at.'.value.formula: {nope}: unknown field "nope".'];
        yield 'a formula that reads a check' => [self::FAIL_EFFECT.'.value', ['formula' => '{check:difficulty}'], $at.'.value.formula: {check:difficulty}: a check reference is read only by the bands of a check.'];
        yield 'a formula that reads an unknown tracker' => [self::FAIL_EFFECT.'.value', ['formula' => '{tracker:ghost}'], $at.'.value.formula: {tracker:ghost}: unknown tracker "ghost".'];
        yield 'in a check step\'s own effects' => ['sceneTypes.0.play.0.effects', [$sheet], 'sceneTypes[0].play[0].effects[0]: '.$placement];
        yield 'in a band of a condition step' => ['sceneTypes.0.closing', [['key' => 'tally', 'kind' => 'condition', 'title' => 'Tally', 'tracker' => 'dread', 'bands' => [['effects' => [$sheet]]]]], 'sceneTypes[0].closing[0].bands[0].effects[0]: '.$placement];
        yield 'in an oracle table entry' => ['oracles.tables', [['key' => 'omens', 'name' => 'Omens', 'entries' => [['text' => 'A crow', 'effects' => [$sheet]]]]], 'oracles.tables[0].entries[0].effects[0]: '.$placement];
    }

    #[Test]
    #[DataProvider('refusals')]
    public function anInvalidSheetEffectIsAPlayError(string $path, mixed $value, string $expectedMessage): void
    {
        /** @var array<string, mixed> $content */
        $content = ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-sheet-effects'), $path, $value);

        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains(self::UNREADABLE.$expectedMessage);

        new GameSystemReleaseTranslator()->translate(ReleaseViews::of($content));
    }
}
