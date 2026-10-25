<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\Release;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\Version2\Bands;
use App\Studio\Domain\Release\Version2\Effects;
use App\Studio\Domain\Release\Version2\Formulas;
use App\Studio\Domain\Release\Version2\SheetFields;
use App\Studio\Domain\Release\Version2\StepParts;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Formulas in schema version 2 (ADR 0023): derived sheet fields read the sheet and trackers, band
 * "upTo" and tracker effect "value" formulas read trackers only. Run against valid/v2-scene-types:
 * trackers alarm (0, clock), edge (1), chaos (2) and heat (3); Scene Type 3 closes with a condition
 * step, Scene Type 2 opens with a tracker effect.
 */
#[CoversClass(Formulas::class)]
#[CoversClass(SheetFields::class)]
#[CoversClass(Bands::class)]
#[CoversClass(Effects::class)]
#[CoversClass(StepParts::class)]
final class ReleaseContentFormulasTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private static function fields(string $formula = '({str}-10)/2'): array
    {
        return [
            ['key' => 'str', 'label' => 'Strength', 'type' => 'number'],
            ['key' => 'role', 'label' => 'Role', 'type' => 'text'],
            ['key' => 'gear', 'label' => 'Gear', 'type' => 'list'],
            ['key' => 'hp', 'label' => 'Hit points', 'type' => 'track', 'max' => 10],
            ['key' => 'bonus', 'label' => 'Bonus', 'type' => 'derived', 'formula' => $formula],
        ];
    }

    /**
     * @param list<array<string, mixed>> $fields
     *
     * @return array<mixed>
     */
    private static function sheet(array $fields): array
    {
        return ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-scene-types'), 'sheet', ['fields' => $fields]);
    }

    /**
     * @param list<array<string, mixed>> $bands
     *
     * @return array<mixed>
     */
    private static function bands(array $bands): array
    {
        return ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-scene-types'), 'sceneTypes.3.closing.0.bands', $bands);
    }

    /**
     * @param array<string, mixed> $effect
     *
     * @return array<mixed>
     */
    private static function effect(array $effect): array
    {
        return ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-scene-types'), 'sceneTypes.2.setup.0.effects.0', $effect);
    }

    #[Test]
    public function aSheetWithDerivedFieldsIsValidAndKeepsTheFormulaInCanonicalForm(): void
    {
        $fields = [
            ...self::fields('max({str},{hp.max})+{tracker:edge}'),
            ['key' => 'double', 'label' => 'Double', 'type' => 'derived', 'formula' => '{bonus}*2', 'group' => 'Derived'],
            ['key' => 'early', 'label' => 'Early', 'type' => 'derived', 'formula' => '{double}+{late}'],
            ['key' => 'late', 'label' => 'Late', 'type' => 'derived', 'formula' => '1'],
        ];

        $content = ReleaseContent::fromArray(self::sheet($fields));

        /** @var array{sheet: array{fields: list<array<string, mixed>>}} $canonical */
        $canonical = $content->toArray();
        self::assertSame(['key', 'label', 'type', 'formula'], array_keys($canonical['sheet']['fields'][4]));
        self::assertSame(['key', 'label', 'group', 'type', 'formula'], array_keys($canonical['sheet']['fields'][5]));
        self::assertSame('max({str},{hp.max})+{tracker:edge}', $canonical['sheet']['fields'][4]['formula']);
        /** @var array<mixed> $decoded */
        $decoded = json_decode(json_encode($canonical, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame($content->hash(), ReleaseContent::fromArray($decoded)->hash());
    }

    #[Test]
    public function formulasOnBandsAndTrackerEffectsAreValidAndKeptInCanonicalForm(): void
    {
        $bands = ReleaseContent::fromArray(self::bands([['upTo' => ['formula' => '{tracker:edge}*2']], ['upTo' => 9], ['upTo' => ['tracker' => 'heat']], []]));
        $effect = ReleaseContent::fromArray(self::effect(['kind' => 'tracker', 'tracker' => 'alarm', 'op' => 'add', 'value' => ['formula' => 'min({tracker:chaos},3)-{tracker:edge}']]));

        self::assertStringContainsString('"upTo":{"formula":"{tracker:edge}*2"}', json_encode($bands->toArray(), \JSON_THROW_ON_ERROR));
        self::assertStringContainsString('"value":{"formula":"min({tracker:chaos},3)-{tracker:edge}"}', json_encode($effect->toArray(), \JSON_THROW_ON_ERROR));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function nonLiteralLevelBounds(): iterable
    {
        yield 'formula' => [['formula' => '{tracker:edge}']];
        yield 'tracker' => [['tracker' => 'edge']];
    }

    /**
     * @param array<string, mixed> $upTo
     */
    #[Test]
    #[DataProvider('nonLiteralLevelBounds')]
    public function aCounterLevelBoundIsAlwaysALiteral(array $upTo): void
    {
        $release = ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-scene-types'), 'trackers.1.levels', [['upTo' => $upTo, 'label' => 'Low'], ['label' => 'Rest']]);

        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains('trackers[1].levels[0].upTo: must be an integer');
        ReleaseContent::fromArray($release);
    }

    #[Test]
    public function aMalformedFormulaInTheFlowIsOneSyntaxErrorAndNeverACrash(): void
    {
        $release = self::bands([['upTo' => ['formula' => '{tracker:edge}+']], []]);

        try {
            ReleaseContent::fromArray($release);
            self::fail('Expected InvalidReleaseContent.');
        } catch (InvalidReleaseContent $invalidReleaseContent) {
            self::assertStringStartsWith('sceneTypes[3].closing[0].bands[0].upTo.formula: Unexpected end of the formula', $invalidReleaseContent->getMessage());
            self::assertSame(1, substr_count($invalidReleaseContent->getMessage(), 'Unexpected end'));
        }
    }

    #[Test]
    public function aFormulaUpToIsLeftOutOfTheLiteralOrderingButCountsAsAnUpToForTheLastBandRule(): void
    {
        // A literal 3 after a formula bound is not compared with anything, and 5 > 3 still holds across it.
        ReleaseContent::fromArray(self::bands([['upTo' => 3], ['upTo' => ['formula' => '{tracker:edge}']], ['upTo' => 5], []]));

        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains('sceneTypes[3].closing[0].bands[1].upTo: the last band catches the rest and must omit upTo');
        ReleaseContent::fromArray(self::bands([['upTo' => 3], ['upTo' => ['formula' => '{tracker:edge}']]]));
    }

    #[Test]
    public function aFormulaUpToCountsAsAnUpToForTheBandsBeforeTheLast(): void
    {
        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains('sceneTypes[3].closing[0].bands[0].upTo: required, only the last band omits it');
        ReleaseContent::fromArray(self::bands([[], []]));
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function invalidFormulas(): iterable
    {
        // Syntax.
        yield 'derived syntax error' => [self::sheet(self::fields('{str}+')), 'sheet.fields[4].formula: Unexpected end of the formula'];
        yield 'derived unknown function' => [self::sheet(self::fields('sum({str},1)')), 'sheet.fields[4].formula: Unknown name "sum"'];
        yield 'derived blank' => [self::sheet(self::fields('   ')), 'sheet.fields[4].formula: must not be blank'];
        yield 'derived too long' => [self::sheet(self::fields(str_repeat('1+', 100).'1')), 'sheet.fields[4].formula: must be at most 200 characters'];
        yield 'derived not a string' => [self::sheet([...\array_slice(self::fields(), 0, 4), ['key' => 'bonus', 'label' => 'Bonus', 'type' => 'derived', 'formula' => 3]]), 'sheet.fields[4].formula: must be a string'];
        yield 'derived without formula' => [self::sheet([['key' => 'bonus', 'label' => 'Bonus', 'type' => 'derived']]), 'sheet.fields[0].formula: required'];
        yield 'derived with a number property' => [self::sheet([['key' => 'bonus', 'label' => 'Bonus', 'type' => 'derived', 'formula' => '1', 'min' => 0]]), 'sheet.fields[0].min: unknown property'];
        yield 'band syntax error' => [self::bands([['upTo' => ['formula' => '(1']], []]), 'sceneTypes[3].closing[0].bands[0].upTo.formula: Unexpected end of the formula'];
        yield 'effect syntax error' => [self::effect(['kind' => 'tracker', 'tracker' => 'alarm', 'op' => 'add', 'value' => ['formula' => '1 2']]), 'sceneTypes[2].setup[0].effects[0].value.formula: Unexpected "2"'];

        // Sheet references.
        yield 'unknown field' => [self::sheet(self::fields('{dex}')), 'sheet.fields[4].formula: {dex}: unknown field "dex"'];
        yield 'reading a text field' => [self::sheet(self::fields('{role}+1')), 'sheet.fields[4].formula: {role}: field "role" is a text field, a formula reads number, track, conditions and derived fields only'];
        yield 'reading a list field' => [self::sheet(self::fields('{gear}')), 'sheet.fields[4].formula: {gear}: field "gear" is a list field'];
        yield 'max of a number field' => [self::sheet(self::fields('{str.max}')), 'sheet.fields[4].formula: {str.max}: field "str" is a number field, only a track field has a max'];
        yield 'max of an unknown field' => [self::sheet(self::fields('{ghost.max}')), 'sheet.fields[4].formula: {ghost.max}: unknown field "ghost"'];
        yield 'unknown tracker in a sheet formula' => [self::sheet(self::fields('{tracker:noise}')), 'sheet.fields[4].formula: {tracker:noise}: unknown tracker "noise"'];
        yield 'second derived field is the one named' => [self::sheet([...self::fields(), ['key' => 'other', 'label' => 'Other', 'type' => 'derived', 'formula' => '{dex}']]), 'sheet.fields[5].formula: {dex}: unknown field "dex"'];
        yield 'check reference in a derived formula' => [self::sheet(self::fields('{check:difficulty}')), 'sheet.fields[4].formula: {check:difficulty}: a check reference is read only by the bands of a check'];
        yield 'check reference named like a field' => [self::sheet(self::fields('{check:str}')), 'sheet.fields[4].formula: {check:str}: a check reference is read only by the bands of a check'];

        // Cycles.
        yield 'self reference' => [self::sheet(self::fields('{bonus}+1')), 'sheet.fields[4].formula: Derived values depend on each other in a cycle: bonus -> bonus.'];
        yield 'two field cycle' => [self::sheet([
            ['key' => 'a', 'label' => 'A', 'type' => 'derived', 'formula' => '{b}'],
            ['key' => 'b', 'label' => 'B', 'type' => 'derived', 'formula' => '{a}'],
        ]), 'sheet.fields[0].formula: Derived values depend on each other in a cycle: a -> b -> a.'];
        yield 'cycle past a valid chain' => [self::sheet([
            ['key' => 'ok', 'label' => 'Ok', 'type' => 'derived', 'formula' => '1'],
            ['key' => 'a', 'label' => 'A', 'type' => 'derived', 'formula' => '{ok}+{b}'],
            ['key' => 'b', 'label' => 'B', 'type' => 'derived', 'formula' => '{a}'],
        ]), 'sheet.fields[1].formula: Derived values depend on each other in a cycle: a -> b -> a.'];

        // Flow scope: trackers only.
        yield 'band formula reading a field' => [self::bands([['upTo' => ['formula' => '{str}']], []]), 'sceneTypes[3].closing[0].bands[0].upTo.formula: {str}: a flow formula reads trackers only, write {tracker:key}'];
        yield 'band formula reading a track max' => [self::bands([['upTo' => ['formula' => '{hp.max}']], []]), 'sceneTypes[3].closing[0].bands[0].upTo.formula: {hp.max}: a flow formula reads trackers only'];
        yield 'band formula reading an unknown tracker' => [self::bands([['upTo' => ['formula' => '{tracker:noise}']], []]), 'sceneTypes[3].closing[0].bands[0].upTo.formula: {tracker:noise}: unknown tracker "noise"'];
        yield 'effect formula reading a field' => [self::effect(['kind' => 'tracker', 'tracker' => 'alarm', 'op' => 'add', 'value' => ['formula' => '{str}+1']]), 'sceneTypes[2].setup[0].effects[0].value.formula: {str}: a flow formula reads trackers only'];
        yield 'band formula reading a check reference' => [self::bands([['upTo' => ['formula' => '{check:difficulty}']], []]), 'sceneTypes[3].closing[0].bands[0].upTo.formula: {check:difficulty}: a flow formula reads trackers only'];
        yield 'effect formula reading a check reference' => [self::effect(['kind' => 'tracker', 'tracker' => 'alarm', 'op' => 'add', 'value' => ['formula' => '{check:difficulty}']]), 'sceneTypes[2].setup[0].effects[0].value.formula: {check:difficulty}: a flow formula reads trackers only'];
        yield 'effect formula reading an unknown tracker' => [self::effect(['kind' => 'tracker', 'tracker' => 'alarm', 'op' => 'add', 'value' => ['formula' => '{tracker:noise}']]), 'sceneTypes[2].setup[0].effects[0].value.formula: {tracker:noise}: unknown tracker "noise"'];

        // Shape.
        yield 'band formula with another property' => [self::bands([['upTo' => ['formula' => '1', 'plus' => 1]], []]), 'sceneTypes[3].closing[0].bands[0].upTo.plus: unknown property'];
        yield 'band formula next to a tracker' => [self::bands([['upTo' => ['formula' => '1', 'tracker' => 'edge']], []]), 'sceneTypes[3].closing[0].bands[0].upTo.tracker: unknown property'];
        yield 'band formula empty' => [self::bands([['upTo' => ['formula' => '']], []]), 'sceneTypes[3].closing[0].bands[0].upTo.formula: must not be empty'];
        yield 'effect formula not a string' => [self::effect(['kind' => 'tracker', 'tracker' => 'alarm', 'op' => 'add', 'value' => ['formula' => 3]]), 'sceneTypes[2].setup[0].effects[0].value.formula: must be a string'];
        yield 'effect formula with another property' => [self::effect(['kind' => 'tracker', 'tracker' => 'alarm', 'op' => 'add', 'value' => ['formula' => '1', 'plus' => 1]]), 'sceneTypes[2].setup[0].effects[0].value.plus: unknown property'];
    }

    /**
     * @param array<mixed>     $release
     * @param non-empty-string $expectedPrefix
     */
    #[Test]
    #[DataProvider('invalidFormulas')]
    public function itRejectsInvalidFormulasNamingThePath(array $release, string $expectedPrefix): void
    {
        try {
            ReleaseContent::fromArray($release);
            self::fail('Expected InvalidReleaseContent.');
        } catch (InvalidReleaseContent $invalidReleaseContent) {
            self::assertStringStartsWith($expectedPrefix, $invalidReleaseContent->getMessage());
        }
    }

    #[Test]
    public function aFormulaReadingATrackerOutsideTheFlowIsRejectedLikeATrackerReference(): void
    {
        $example = ReleaseArrays::fixture('valid/v2-contract-doc-example');
        $example = ReleaseArrays::with($example, 'trackers.3', ['key' => 'noise', 'name' => 'Noise', 'kind' => 'clock', 'segments' => 4]);
        $example = ReleaseArrays::with($example, 'sceneTypes.0.setup.0.effects', [['kind' => 'tracker', 'tracker' => 'alarm', 'op' => 'add', 'value' => ['formula' => '{tracker:noise}']]]);

        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains('is not one of the trackers of flow "heist"');
        ReleaseContent::fromArray($example);
    }

    #[Test]
    public function aFormulaReadsAConditionsFieldAsTheNumberMarked(): void
    {
        /** @var array{sheet: array{fields: list<array<string, mixed>>}, checks: list<array{pool?: array{count: array{formula: string}}, bands: list<array{upTo: array{formula: string}}>}>} $content */
        $content = ReleaseContent::fromArray(ReleaseArrays::fixture('valid/v2-dice-pools'))->toArray();

        self::assertSame('{physical} + {mental}', $content['sheet']['fields'][4]['formula'] ?? null);
        self::assertSame('{agility} + {stealth} - {physical}', $content['checks'][0]['pool']['count']['formula'] ?? null);
        self::assertSame('{check:difficulty} - 1 + {strain}', $content['checks'][1]['bands'][0]['upTo']['formula'] ?? null);
    }

    /**
     * @return iterable<string, array{string, string, string}> the path changed in valid/v2-dice-pools, the formula and the message
     */
    public static function conditionsFormulaRefusals(): iterable
    {
        yield 'a max of a conditions field in a derived field' => ['sheet.fields.4.formula', '{physical.max}', 'sheet.fields[4].formula: {physical.max}: field "physical" is a conditions field, only a track field has a max'];
        yield 'a max of a conditions field in a pool count' => ['checks.0.pool.count.formula', '{physical.max}', 'checks[0].pool.count.formula: {physical.max}: field "physical" is a conditions field, only a track field has a max'];
        yield 'an unknown field next to a conditions field' => ['sheet.fields.4.formula', '{physical} + {ghost}', 'sheet.fields[4].formula: {ghost}: unknown field "ghost"'];
        yield 'a derived field reading itself through conditions' => ['sheet.fields.4.formula', '{strain} + {physical}', 'sheet.fields[4].formula: '];
    }

    #[Test]
    #[DataProvider('conditionsFormulaRefusals')]
    public function aConditionsFormulaOutOfScopeIsRefused(string $path, string $formula, string $message): void
    {
        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains($message);

        ReleaseContent::fromArray(ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-dice-pools'), $path, $formula));
    }

    #[Test]
    public function aBandFormulaReadsConditionsButATrackerEffectValueStillReadsTrackersOnly(): void
    {
        $release = ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-dice-pools'), 'checks.1.bands.0.upTo', ['formula' => '{tracker:dread} + {physical}']);

        self::assertNotSame('', ReleaseContent::fromArray($release)->hash(), 'A band formula of a check reads the sheet, conditions included.');

        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains('checks[0].push.effects[1].value.formula: {physical}: a flow formula reads trackers only');

        ReleaseContent::fromArray(ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-dice-pools'), 'checks.0.push.effects.1.value', ['formula' => '{physical}']));
    }
}
