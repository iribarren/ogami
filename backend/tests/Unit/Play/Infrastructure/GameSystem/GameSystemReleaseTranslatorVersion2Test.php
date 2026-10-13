<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Infrastructure\GameSystem;

use App\Formula\Domain\Formula;
use App\Play\Domain\GameSystem\FactSlot;
use App\Play\Domain\GameSystem\FactSlotType;
use App\Play\Domain\GameSystem\Flow\Band;
use App\Play\Domain\GameSystem\Flow\ChoiceOption;
use App\Play\Domain\GameSystem\Flow\ChoiceStep;
use App\Play\Domain\GameSystem\Flow\ConditionStep;
use App\Play\Domain\GameSystem\Flow\EndPhaseEffect;
use App\Play\Domain\GameSystem\Flow\NextSceneEffect;
use App\Play\Domain\GameSystem\Flow\OracleBranches;
use App\Play\Domain\GameSystem\Flow\OracleStep;
use App\Play\Domain\GameSystem\Flow\Outcome;
use App\Play\Domain\GameSystem\Flow\PromptStep;
use App\Play\Domain\GameSystem\Flow\RollStep;
use App\Play\Domain\GameSystem\Flow\SceneTitleEffect;
use App\Play\Domain\GameSystem\Flow\StepList;
use App\Play\Domain\GameSystem\Flow\SwitchSceneTypeEffect;
use App\Play\Domain\GameSystem\Flow\TableBranch;
use App\Play\Domain\GameSystem\Flow\TableStep;
use App\Play\Domain\GameSystem\Flow\TrackerEffect;
use App\Play\Domain\GameSystem\Flow\TrackerOperation;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\SceneType;
use App\Play\Domain\GameSystem\TableEntryMetadata;
use App\Play\Domain\GameSystem\Tracker;
use App\Play\Domain\GameSystem\TrackerLevel;
use App\Play\Infrastructure\GameSystem\GameSystemReleaseTranslator;
use App\Randomness\Domain\Oracle\LikelihoodOracle;
use App\Randomness\Domain\Oracle\YesNoAnswer;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Play's anti-corruption layer reads the catalog of schema version 2: trackers, fact slots, table
 * entry metadata, the chaos tracker and Scene Types with their steps (flows: the flows test).
 */
#[CoversClass(GameSystemReleaseTranslator::class)]
#[CoversClass(GameSystemSnapshot::class)]
#[CoversClass(Tracker::class)]
#[CoversClass(OracleBranches::class)]
#[CoversClass(TableStep::class)]
#[CoversClass(ChoiceStep::class)]
#[CoversClass(StepList::class)]
final class GameSystemReleaseTranslatorVersion2Test extends TestCase
{
    private function snapshot(string $fixture = 'valid/v2-scene-types'): GameSystemSnapshot
    {
        return new GameSystemReleaseTranslator()->translate(ReleaseViews::of(ReleaseViews::fixtureContent($fixture)));
    }

    #[Test]
    public function itMapsTrackers(): void
    {
        self::assertEquals([
            Tracker::clock('alarm', 'Alarm', 'At 6/6 security locks down: you run', 6),
            Tracker::counter('edge', 'Edge', null, 0, 3, 0, [new TrackerLevel(0, 'No edge'), new TrackerLevel(null, 'An edge')]),
            Tracker::counter('chaos', 'Chaos factor', null, 1, 9, 5),
        ], $this->snapshot('valid/v2-contract-doc-example')->trackers());

        $snapshot = $this->snapshot();
        self::assertSame(6, $snapshot->tracker('alarm')?->segments());
        self::assertSame(-5, $snapshot->tracker('heat')?->min);
        self::assertNull($snapshot->tracker('heat')->segments());
        self::assertNull($snapshot->tracker('unknown'));
    }

    #[Test]
    public function itMapsFactSlots(): void
    {
        self::assertEquals([new FactSlot('target', 'Target', FactSlotType::Text)], $this->snapshot('valid/v2-contract-doc-example')->factSlots());
        self::assertSame([], $this->snapshot()->factSlots());
    }

    #[Test]
    public function itBindsTheChaosFactorToItsTracker(): void
    {
        $fate = $this->snapshot()->likelihoodOracle('fate');

        self::assertSame('chaos', $fate->chaosTracker());
        self::assertEquals(LikelihoodOracle::fromArray([
            'sides' => 100,
            'levels' => [['key' => 'unlikely', 'label' => 'Unlikely', 'target' => 35], ['key' => 'even', 'label' => '50/50', 'target' => 50]],
            'chaos' => ['min' => 1, 'max' => 9, 'neutral' => 5, 'shiftPerPoint' => 5],
            'exceptionalPercent' => 20,
        ]), $fate->oracle());
    }

    #[Test]
    public function itCarriesTheRandomEventRuleIntoTheSnapshot(): void
    {
        $oracle = $this->snapshot('valid/v2-random-event')->likelihoodOracle('fate')->oracle();

        self::assertSame('focus', $oracle->randomEventTable());
        self::assertNull($this->snapshot()->likelihoodOracle('fate')->oracle()->randomEventTable());
    }

    #[Test]
    public function aRandomEventRuleWithoutChaosIsAPlayError(): void
    {
        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains('oracles.likelihood[0]: A random event needs the chaos factor of a likelihood oracle.');

        $this->translateFateWith(without: 'chaos');
    }

    #[Test]
    public function aRandomEventRuleNamingNoTableIsAPlayError(): void
    {
        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains('oracles.likelihood[0].randomEvent.table: unknown oracle table "ghost".');

        $this->translateFateWith(randomEvent: ['table' => 'ghost']);
    }

    /**
     * Translates the "random-event" fixture with its fate oracle's random event rule replaced, or one of its fields removed.
     *
     * @param array<string, mixed> $randomEvent
     */
    private function translateFateWith(array $randomEvent = ['table' => 'focus'], ?string $without = null): GameSystemSnapshot
    {
        $content = ReleaseViews::fixtureContent('valid/v2-random-event');
        /** @var array{likelihood: list<array<string, mixed>>} $oracles */
        $oracles = $content['oracles'];
        $oracles['likelihood'][0]['randomEvent'] = $randomEvent;
        if (null !== $without) {
            unset($oracles['likelihood'][0][$without]);
        }

        $content['oracles'] = $oracles;

        return new GameSystemReleaseTranslator()->translate(ReleaseViews::of($content));
    }

    #[Test]
    public function itKnowsTheMetadataOfARolledEntry(): void
    {
        $snapshot = $this->snapshot();
        $steps = $snapshot->resolveOracleTable('complications', new ScriptedRandomNumberGenerator(3, 2))->steps();

        self::assertEquals(new TableEntryMetadata('run', 'getaway', []), $snapshot->rolledEntry($steps[0]));
        self::assertEquals(new TableEntryMetadata('rooftops', null, [new SceneTitleEffect('Over the rooftops')]), $snapshot->rolledEntry($steps[1]));

        $patrol = $snapshot->resolveOracleTable('complications', new ScriptedRandomNumberGenerator(1))->steps()[0];
        self::assertEquals(new TableEntryMetadata('patrol', null, [new TrackerEffect('alarm', TrackerOperation::Add, 1)]), $snapshot->rolledEntry($patrol));

        $ranged = $this->snapshot('valid/v2-contract-doc-example');
        $shots = $ranged->resolveOracleTable('heist-scenes', new ScriptedRandomNumberGenerator(5))->steps()[0];
        self::assertEquals(new TableEntryMetadata(null, 'firefight', []), $ranged->rolledEntry($shots));
    }

    #[Test]
    public function itMapsSceneTypesWithEveryStepKindBandAndEffect(): void
    {
        $snapshot = $this->snapshot();

        self::assertSame(['legwork', 'infiltration', 'firefight', 'getaway'], array_map(static fn (SceneType $type): string => $type->key, $snapshot->sceneTypes()));
        self::assertEquals(new SceneType('getaway', 'Getaway', 'Get away clean.', null, ['escape-routes'], new StepList([
            new PromptStep('how', 'How do you get out?', 'Name your way out.', 'Think of the alarm.', true, 'route', [new TrackerEffect('heat', TrackerOperation::Add, Formula::fromString('{tracker:alarm}'))]),
            new PromptStep('skipped', 'Never reached', null, null, false, null, []),
            new TableStep('route', 'Which way?', null, null, false, null, [], 'escape-routes', [
                new TableBranch('rooftops', new Outcome('chase')),
                new TableBranch('sewers', new Outcome(effects: [new TrackerEffect('alarm', TrackerOperation::Set, 0)])),
            ], new Outcome(effects: [new EndPhaseEffect()])),
            new RollStep('chase', 'Do they follow?', null, null, false, null, [], '2d6+1', [
                new Band(Formula::fromString('{tracker:heat}'), new Outcome('end')),
                new Band(7, new Outcome(effects: [new TrackerEffect('heat', TrackerOperation::Add, -1)])),
                new Band(null, new Outcome('end')),
            ]),
        ]), new StepList([
            new OracleStep('ask', 'Are they waiting?', null, null, false, null, [], 'fate', null, new OracleBranches(
                new Outcome('pick'),
                new Outcome('end'),
                new Outcome(effects: [new NextSceneEffect('firefight')]),
                new Outcome(effects: [new SceneTitleEffect('A clean getaway')]),
            )),
            new ChoiceStep('pick', 'Fight or flee?', null, null, true, null, [], [
                new ChoiceOption('fight', 'Fight', new Outcome(effects: [new SwitchSceneTypeEffect('firefight')])),
                new ChoiceOption('flee', 'Flee', new Outcome('end')),
            ], null),
        ]), new StepList([
            new ConditionStep('cool', 'How hot is it?', null, null, false, null, [], 'heat', [
                new Band(Formula::fromString('{tracker:edge}'), new Outcome('end')),
                new Band(3, new Outcome()),
                new Band(null, new Outcome(effects: [new TrackerEffect('heat', TrackerOperation::Set, 0), new EndPhaseEffect()])),
            ]),
            new ConditionStep('lockdown', 'Is the alarm full?', null, null, false, null, [], 'alarm', [new Band(5, new Outcome()), new Band(null, new Outcome())]),
        ])), $snapshot->sceneType('getaway'));
        self::assertSame('Keep it short and loud.', $snapshot->sceneType('firefight')?->tips);
        self::assertNull($snapshot->sceneType('unknown'));
    }

    #[Test]
    public function itsStepsAnswerWhatLaterSlicesLookUp(): void
    {
        $snapshot = $this->snapshot();
        $legwork = $snapshot->sceneType('legwork');
        self::assertInstanceOf(SceneType::class, $legwork);
        $closing = $legwork->closing->step('gain-edge');
        self::assertInstanceOf(ChoiceStep::class, $closing);
        self::assertSame('no', $closing->skip);
        self::assertEquals([new TrackerEffect('edge', TrackerOperation::Add, 1)], $closing->option('yes')?->outcome->effects);
        self::assertNull($closing->option('maybe'));
        self::assertNull($legwork->play->step('gain-edge'));

        $slip = $snapshot->sceneType('infiltration')?->play->step('slip-past');
        self::assertInstanceOf(OracleStep::class, $slip);
        self::assertSame('even', $slip->likelihood);
        self::assertNull($slip->branches->for(YesNoAnswer::Yes));
        self::assertEquals(new Outcome(effects: [new SwitchSceneTypeEffect('firefight')]), $slip->branches->for(YesNoAnswer::ExceptionalNo));
        self::assertSame($slip->branches->no, $slip->branches->for(YesNoAnswer::No));

        $route = $snapshot->sceneType('getaway')?->setup->step('route');
        self::assertInstanceOf(TableStep::class, $route);
        self::assertSame('chase', $route->outcomeFor('rooftops')?->next);
        self::assertSame($route->otherwise, $route->outcomeFor(null));
    }

    #[Test]
    public function aNullMandatoryIsSuggested(): void
    {
        /** @var array<string, mixed> $content */
        $content = ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-scene-types'), 'sceneTypes.0.setup.0.mandatory', null);

        $snapshot = new GameSystemReleaseTranslator()->translate(ReleaseViews::of($content));

        self::assertFalse($snapshot->sceneType('legwork')?->setup->step('goal')?->mandatory);
    }

    #[Test]
    public function anExceptionalAnswerWithoutItsBranchUsesThePlainOne(): void
    {
        $branches = new OracleBranches(new Outcome('yes'), new Outcome('no'));

        self::assertSame($branches->yes, $branches->for(YesNoAnswer::ExceptionalYes));
        self::assertSame($branches->no, $branches->for(YesNoAnswer::ExceptionalNo));
    }

    #[Test]
    public function aVersion1ReleaseHasNoVersion2Parts(): void
    {
        $snapshot = new GameSystemReleaseTranslator()->translate(ReleaseViews::of(ReleaseViews::contractDocExampleContent()));

        self::assertSame([], $snapshot->trackers());
        self::assertSame([], $snapshot->factSlots());
        self::assertSame([], $snapshot->sceneTypes());
        self::assertNull($snapshot->likelihoodOracle('fate')->chaosTracker());
        $step = $snapshot->resolveOracleTable('weather', new ScriptedRandomNumberGenerator(1))->steps()[0];
        self::assertNull($snapshot->rolledEntry($step));
    }

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function malformedParts(): iterable
    {
        yield 'tracker kind' => ['trackers.0.kind', 'gauge', 'trackers[0].kind: unknown tracker kind "gauge".'];
        yield 'counter bound' => ['trackers.1.max', '3', 'trackers[1].max: must be an integer.'];
        yield 'fact slot type' => ['factSlots', [['key' => 'who', 'label' => 'Who', 'type' => 'place']], 'factSlots[0].type: unknown fact slot type "place".'];
        yield 'step kind' => ['sceneTypes.0.setup.0.kind', 'dance', 'sceneTypes[0].setup[0].kind: unknown step kind "dance".'];
        yield 'duplicate step key' => ['sceneTypes.3.setup.1.key', 'how', 'sceneTypes[3].setup[1].key: duplicate key "how".'];
        yield 'effect kind' => ['sceneTypes.2.setup.0.effects.1.kind', 'teleport', 'sceneTypes[2].setup[0].effects[1].kind: unknown effect kind "teleport".'];
        yield 'tracker operation' => ['sceneTypes.2.setup.0.effects.0.op', 'mul', 'sceneTypes[2].setup[0].effects[0].op: unknown tracker operation "mul".'];
        yield 'band upTo' => ['sceneTypes.3.closing.1.bands.0.upTo', 'five', 'sceneTypes[3].closing[1].bands[0].upTo: must be an integer, {"tracker": key} or {"formula": string}.'];
        yield 'entry effects' => ['oracles.tables.0.entries.0.effects', 'none', 'oracles.tables[0].entries[0].effects: must be a list.'];
        yield 'duplicate Scene Type' => ['sceneTypes.1.key', 'legwork', 'sceneTypes[1].key: duplicate key "legwork".'];
        yield 'duplicate tracker' => ['trackers.1.key', 'alarm', 'trackers[1].key: duplicate key "alarm".'];
        yield 'non-boolean mandatory' => ['sceneTypes.0.setup.0.mandatory', 'yes', 'sceneTypes[0].setup[0].mandatory: must be a boolean.'];
        yield 'integer mandatory' => ['sceneTypes.3.play.1.mandatory', 1, 'sceneTypes[3].play[1].mandatory: must be a boolean.'];
    }

    #[Test]
    #[DataProvider('malformedParts')]
    public function aMalformedPartIsAPlayError(string $path, mixed $value, string $expectedMessage): void
    {
        $content = ReleaseViews::fixtureContent('valid/v2-scene-types');
        $node = &$content;
        foreach (explode('.', $path) as $segment) {
            \assert(\is_array($node));
            $node = &$node[$segment];
        }

        $node = $value;
        unset($node);
        /** @var array<string, mixed> $edited */
        $edited = $content;

        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains('GameSystem "scene-types" v1 cannot be read by Play: '.$expectedMessage);

        new GameSystemReleaseTranslator()->translate(ReleaseViews::of($edited));
    }

    #[Test]
    public function aFormulaBandBoundAndEffectValueAreReadAsFormulas(): void
    {
        $content = ReleaseViews::fixtureContent('valid/v2-scene-types');
        $content = ReleaseArrays::with($content, 'sceneTypes.3.closing.1.bands.0.upTo', ['formula' => '{tracker:edge} * 2']);
        /** @var array<string, mixed> $content */
        $content = ReleaseArrays::with($content, 'sceneTypes.2.setup.0.effects.0.value', ['formula' => 'max({tracker:chaos}, 3)']);

        $snapshot = new GameSystemReleaseTranslator()->translate(ReleaseViews::of($content));

        $lockdown = $snapshot->sceneType('getaway')?->closing->step('lockdown');
        self::assertInstanceOf(ConditionStep::class, $lockdown);
        self::assertEquals(Formula::fromString('{tracker:edge} * 2'), $lockdown->bands[0]->upTo);
        $firstStep = $snapshot->sceneType('firefight')?->setup->steps[0];
        self::assertInstanceOf(PromptStep::class, $firstStep);
        self::assertInstanceOf(TrackerEffect::class, $firstStep->effects[0]);
        self::assertEquals(Formula::fromString('max({tracker:chaos}, 3)'), $firstStep->effects[0]->value);
    }

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function formulaParts(): iterable
    {
        $band = 'sceneTypes.3.closing.1.bands.0.upTo';
        $bandPath = 'sceneTypes[3].closing[1].bands[0].upTo';
        $value = 'sceneTypes.2.setup.0.effects.0.value';
        $valuePath = 'sceneTypes[2].setup[0].effects[0].value';
        $shape = ': must be an integer, {"tracker": key} or {"formula": string}.';

        yield 'band formula syntax' => [$band, ['formula' => '{tracker:edge} +'], $bandPath.': Unexpected end of the formula'];
        yield 'effect formula syntax' => [$value, ['formula' => '1 2'], $valuePath.': Unexpected "2"'];
        yield 'band formula reading a field' => [$band, ['formula' => '{str} + 1'], $bandPath.': {str}: a flow formula reads trackers only.'];
        yield 'effect formula reading a track max' => [$value, ['formula' => '{hp.max}'], $valuePath.': {hp.max}: a flow formula reads trackers only.'];
        yield 'tracker key breaking the key rule' => [$value, ['tracker' => 'heat} + {tracker:edge'], $valuePath.': '];
        yield 'both tracker and formula' => [$value, ['tracker' => 'edge', 'formula' => '1'], $valuePath.$shape];
        yield 'neither tracker nor formula' => [$value, [], $valuePath.$shape];
        yield 'extra key' => [$band, ['formula' => '1', 'plus' => 1], $bandPath.$shape];
        yield 'formula not a string' => [$band, ['formula' => 3], $bandPath.$shape];
        yield 'band formula reading an undeclared tracker' => [$band, ['formula' => '{tracker:edge} + {tracker:nope}'], $bandPath.': unknown tracker "nope".'];
        yield 'band tracker form naming an undeclared tracker' => [$band, ['tracker' => 'nope'], $bandPath.': unknown tracker "nope".'];
        yield 'effect formula reading an undeclared tracker' => [$value, ['formula' => 'max({tracker:nope}, 1)'], $valuePath.': unknown tracker "nope".'];
        yield 'effect tracker form naming an undeclared tracker' => [$value, ['tracker' => 'nope'], $valuePath.': unknown tracker "nope".'];
        yield 'counter level bound naming a tracker' => ['trackers.1.levels', [['upTo' => ['tracker' => 'nope'], 'label' => 'Low']], 'trackers[1].levels[0].upTo: must be an integer'];
        yield 'formula as a bare string' => [$value, '{tracker:edge}', $valuePath.$shape];
    }

    /**
     * Studio validates formulas, but Play re-checks what it reads (the ACL trusts nothing stored).
     */
    #[Test]
    #[DataProvider('formulaParts')]
    public function aMalformedFormulaIsAPlayError(string $path, mixed $value, string $expectedMessage): void
    {
        $content = ReleaseViews::fixtureContent('valid/v2-scene-types');
        $node = &$content;
        foreach (explode('.', $path) as $segment) {
            \assert(\is_array($node));
            $node = &$node[$segment];
        }

        $node = $value;
        unset($node);
        /** @var array<string, mixed> $edited */
        $edited = $content;

        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains($expectedMessage);

        new GameSystemReleaseTranslator()->translate(ReleaseViews::of($edited));
    }
}
