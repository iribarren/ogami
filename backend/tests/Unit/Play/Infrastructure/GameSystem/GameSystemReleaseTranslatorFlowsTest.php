<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Infrastructure\GameSystem;

use App\Play\Domain\GameSystem\Flow\Band;
use App\Play\Domain\GameSystem\Flow\ConditionStep;
use App\Play\Domain\GameSystem\Flow\EndPhaseEffect;
use App\Play\Domain\GameSystem\Flow\Flow;
use App\Play\Domain\GameSystem\Flow\FlowView;
use App\Play\Domain\GameSystem\Flow\Outcome;
use App\Play\Domain\GameSystem\Flow\Phase;
use App\Play\Domain\GameSystem\Flow\PhaseMode;
use App\Play\Domain\GameSystem\Flow\PromptStep;
use App\Play\Domain\GameSystem\Flow\RollStep;
use App\Play\Domain\GameSystem\Flow\SceneSelection;
use App\Play\Domain\GameSystem\Flow\StepList;
use App\Play\Domain\GameSystem\Flow\TrackerEffect;
use App\Play\Domain\GameSystem\Flow\TrackerOperation;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Infrastructure\GameSystem\GameSystemReleaseTranslator;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Play's anti-corruption layer reads the flows of schema version 2: phases, Scene selection and
 * Hooks. "valid/v2-every-part" has two flows, every hook and the three selection rules.
 */
#[CoversClass(GameSystemReleaseTranslator::class)]
#[CoversClass(GameSystemSnapshot::class)]
#[CoversClass(Flow::class)]
#[CoversClass(SceneSelection::class)]
final class GameSystemReleaseTranslatorFlowsTest extends TestCase
{
    private const array HOOKS = ['sessionOpening', 'sessionClosing', 'phaseOpening', 'phaseClosing', 'sceneOpening', 'sceneClosing', 'worldTurn'];

    /**
     * @param array<mixed> $content
     */
    private function translate(array $content): GameSystemSnapshot
    {
        /** @var array<string, mixed> $typed */
        $typed = $content;

        return new GameSystemReleaseTranslator()->translate(ReleaseViews::of($typed));
    }

    private function snapshot(string $fixture = 'valid/v2-every-part'): GameSystemSnapshot
    {
        return $this->translate(ReleaseViews::fixtureContent($fixture));
    }

    /**
     * @return array<string, list<string>> step keys per hook
     */
    private function hookSteps(?Phase $phase): array
    {
        $steps = [];
        foreach (self::HOOKS as $hook) {
            $list = $phase?->{$hook};
            \assert($list instanceof StepList);
            $steps[$hook] = array_map(static fn (\App\Play\Domain\GameSystem\Flow\Step $step): string => $step->key, $list->steps);
        }

        return $steps;
    }

    #[Test]
    public function itMapsFlowsWithTheirPhases(): void
    {
        $snapshot = $this->snapshot();

        self::assertSame(['heist', 'quick'], array_map(static fn (Flow $flow): string => $flow->key, $snapshot->flows()));
        self::assertEquals(
            new Flow('quick', 'Quick job', null, null, false, FlowView::Journal, ['fate'], ['alarm', 'edge'], [
                new Phase('only', 'Only', null, PhaseMode::Once, SceneSelection::player(['legwork', 'firefight'])),
            ]),
            $snapshot->flow('quick'),
        );

        $heist = $snapshot->flow('heist');
        self::assertInstanceOf(Flow::class, $heist);
        self::assertSame(['Heist', 'Plan the job, break in, get out.', 'Learn what you can, then go in. Watch the alarm.', true, FlowView::Focus], [$heist->name, $heist->description, $heist->introduction, $heist->default, $heist->defaultView]);
        self::assertSame(['fate', 'complications', 'escape-routes'], $heist->oracles);
        self::assertSame(['alarm', 'edge', 'chaos', 'heat'], $heist->trackers);
        self::assertEquals(new Phase(
            'legwork',
            'Legwork',
            'Act 1',
            PhaseMode::Loop,
            SceneSelection::player(['legwork']),
            sessionOpening: new StepList([new PromptStep('recap', 'Where did you leave off?', null, null, false, null, [])]),
            worldTurn: new StepList([new RollStep('word', 'Does word get around?', null, null, false, null, [], '1d6', [
                new Band(1, new Outcome(effects: [new TrackerEffect('alarm', TrackerOperation::Add, 1)])),
                new Band(null, new Outcome()),
            ])]),
        ), $heist->phases[0]);
        self::assertSame(['the-heist', 'Act 2', PhaseMode::Loop], [$heist->phases[1]->key, $heist->phases[1]->act, $heist->phases[1]->mode]);
        self::assertSame(['getaway', null, PhaseMode::Once], [$heist->phases[2]->key, $heist->phases[2]->act, $heist->phases[2]->mode]);
    }

    #[Test]
    public function itMapsEveryHook(): void
    {
        $heist = $this->snapshot()->flow('heist');

        self::assertSame([
            'sessionOpening' => ['sessionopening'],
            'sessionClosing' => ['sessionclosing'],
            'phaseOpening' => ['phaseopening'],
            'phaseClosing' => ['debrief'],
            'sceneOpening' => ['sceneopening'],
            'sceneClosing' => ['sceneclosing'],
            'worldTurn' => ['worldturn'],
        ], $this->hookSteps($heist?->phase('getaway')));
        self::assertSame([
            'sessionOpening' => [],
            'sessionClosing' => [],
            'phaseOpening' => [],
            'phaseClosing' => [],
            'sceneOpening' => ['pressure', 'notice'],
            'sceneClosing' => ['lockdown'],
            'worldTurn' => ['response', 'guards'],
        ], $this->hookSteps($heist?->phase('the-heist')));
        $lockdown = $heist?->phase('the-heist')?->sceneClosing->step('lockdown');
        self::assertInstanceOf(ConditionStep::class, $lockdown);
        self::assertEquals(new Outcome(effects: [new EndPhaseEffect()]), $lockdown->bands[1]->outcome);
    }

    #[Test]
    public function itMapsTheThreeSelectionRules(): void
    {
        $heist = $this->snapshot()->flow('heist');
        self::assertInstanceOf(Flow::class, $heist);

        self::assertEquals(
            [SceneSelection::player(['legwork']), SceneSelection::oracle('heist-scenes'), SceneSelection::sequence(['getaway', 'firefight', 'getaway'])],
            array_map(static fn (Phase $phase): SceneSelection => $phase->selection, $heist->phases),
        );
        self::assertSame(
            ['legwork', null, null],
            array_map(static fn (Phase $phase): ?string => $phase->selection->autoPick(), $heist->phases),
        );
        self::assertNull($this->snapshot()->flow('quick')?->phases[0]->selection->autoPick());
        self::assertSame('heist-scenes', $heist->phases[1]->selection->table);
    }

    #[Test]
    public function itMapsTheContractDocExampleFlow(): void
    {
        $snapshot = $this->snapshot('valid/v2-contract-doc-example');
        $heist = $snapshot->defaultFlow();

        self::assertInstanceOf(Flow::class, $heist);
        self::assertSame([$heist], $snapshot->flows());
        self::assertSame(['legwork', 'the-heist', 'getaway'], array_map(static fn (Phase $phase): string => $phase->key, $heist->phases));
        self::assertEquals(SceneSelection::sequence(['firefight']), $heist->phase('getaway')?->selection);
        self::assertSame('firefight', $heist->phase('getaway')?->selection->autoPick());
        self::assertSame(['debrief'], $this->hookSteps($heist->phase('getaway'))['phaseClosing']);
    }

    #[Test]
    public function itLooksUpFlowsAndPhases(): void
    {
        $snapshot = $this->snapshot();
        $heist = $snapshot->flow('heist');
        self::assertInstanceOf(Flow::class, $heist);

        self::assertSame($heist, $snapshot->defaultFlow());
        self::assertNull($snapshot->flow('unknown'));
        self::assertSame($heist->phases[1], $heist->phase('the-heist'));
        self::assertSame(1, $heist->phaseIndex('the-heist'));
        self::assertSame($heist->phases[2], $heist->phaseAt(2));
        self::assertNull($heist->phaseAt(3));
        self::assertNull($heist->phase('unknown'));
        self::assertNull($heist->phaseIndex('unknown'));
    }

    #[Test]
    public function withoutADefaultFlowThereIsNoDefault(): void
    {
        $snapshot = $this->translate(ReleaseArrays::without(ReleaseViews::fixtureContent('valid/v2-every-part'), 'flows.0.default'));

        self::assertNull($snapshot->defaultFlow());
        self::assertCount(2, $snapshot->flows());
    }

    #[Test]
    public function aNullDefaultIsNotTheDefault(): void
    {
        $snapshot = $this->translate(ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-every-part'), 'flows.0.default', null));

        self::assertNull($snapshot->defaultFlow());
        self::assertFalse($snapshot->flow('heist')?->default);
    }

    #[Test]
    public function releasesWithoutFlowsHaveNone(): void
    {
        foreach ([$this->snapshot('valid/v2-minimal'), $this->snapshot('valid/v2-scene-types'), $this->translate(ReleaseViews::contractDocExampleContent())] as $snapshot) {
            self::assertSame([], $snapshot->flows());
            self::assertNull($snapshot->defaultFlow());
        }
    }

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function malformedParts(): iterable
    {
        yield 'flows' => ['flows', 'none', 'flows: must be a list.'];
        yield 'default view' => ['flows.0.defaultView', 'split', 'flows[0].defaultView: unknown default view "split".'];
        yield 'flow oracles' => ['flows.0.oracles', 'fate', 'flows[0].oracles: must be a list.'];
        yield 'no phases' => ['flows.0.phases', [], 'flows[0].phases: must list at least one phase.'];
        yield 'phase mode' => ['flows.0.phases.0.mode', 'twice', 'flows[0].phases[0].mode: unknown phase mode "twice".'];
        yield 'selection rule' => ['flows.0.phases.0.selection.rule', 'dice', 'flows[0].phases[0].selection.rule: unknown selection rule "dice".'];
        yield 'no selected Scene Type' => ['flows.0.phases.0.selection.sceneTypes', [], 'flows[0].phases[0].selection.sceneTypes: must list at least one Scene Type.'];
        yield 'oracle selection table' => ['flows.0.phases.1.selection.table', 7, 'flows[0].phases[1].selection.table: must be a string.'];
        yield 'hook step' => ['flows.0.phases.2.worldTurn.0.kind', 'pick', 'flows[0].phases[2].worldTurn[0].kind: unknown step kind "pick".'];
        yield 'duplicate phase key' => ['flows.0.phases.1.key', 'legwork', 'flows[0].phases[1].key: duplicate key "legwork".'];
        yield 'duplicate flow key' => ['flows.1.key', 'heist', 'flows[1].key: duplicate key "heist".'];
        yield 'second default' => ['flows.1.default', true, 'flows[1].default: only one flow may be the default.'];
        yield 'non-boolean default' => ['flows.0.default', 'yes', 'flows[0].default: must be a boolean.'];
        yield 'integer default' => ['flows.1.default', 1, 'flows[1].default: must be a boolean.'];
    }

    #[Test]
    #[DataProvider('malformedParts')]
    public function aMalformedFlowIsAPlayError(string $path, mixed $value, string $expectedMessage): void
    {
        $content = ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-every-part'), $path, $value);

        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains('cannot be read by Play: '.$expectedMessage);

        $this->translate($content);
    }
}
