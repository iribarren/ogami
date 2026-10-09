<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\Release;

use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\Version2\AuthoringWarnings;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Decision 7 of ADR 0018: a threshold consequence must lower its tracker, or it fires on every
 * turn. In the contract doc example, the-heist's world turn (flows[0].phases[1].worldTurn) is a
 * condition on "alarm" followed by a roll whose first band forces nextScene firefight, and the
 * Firefight setup (sceneTypes[2].setup[0]) sets the alarm to 0.
 */
#[CoversClass(AuthoringWarnings::class)]
#[CoversClass(ReleaseContent::class)]
final class AuthoringWarningsTest extends TestCase
{
    private const string FIREFIGHT_EFFECT = 'sceneTypes.2.setup.0.effects.0';
    private const string WORLD_TURN = 'flows.0.phases.1.worldTurn';
    private const string HEIST_WARNING = 'flows[0].phases[1].worldTurn[1]: nextScene firefight does not lower tracker alarm; the consequence may fire every turn';

    /**
     * @return array<mixed>
     */
    private static function release(): array
    {
        return ReleaseArrays::fixture('valid/v2-contract-doc-example');
    }

    /**
     * @param array<string, mixed> $effect
     *
     * @return array<mixed>
     */
    private static function firefightEffect(array $effect): array
    {
        return ReleaseArrays::with(self::release(), self::FIREFIGHT_EFFECT, $effect);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function consequencesLoweringTheirTracker(): iterable
    {
        yield 'set' => [self::release()];
        yield 'set to a tracker value' => [self::firefightEffect(['kind' => 'tracker', 'tracker' => 'alarm', 'op' => 'set', 'value' => ['tracker' => 'edge']])];
        yield 'add a negative literal' => [self::firefightEffect(['kind' => 'tracker', 'tracker' => 'alarm', 'op' => 'add', 'value' => -2])];
        yield 'lowered in a later part of the Scene Type' => [ReleaseArrays::with(
            self::firefightEffect(['kind' => 'sceneTitle', 'title' => 'Firefight']),
            'sceneTypes.2.closing',
            [['key' => 'cool-off', 'kind' => 'roll', 'title' => 'Cool off', 'dice' => '1d6', 'bands' => [['upTo' => 3, 'effects' => [['kind' => 'tracker', 'tracker' => 'alarm', 'op' => 'add', 'value' => -1]]], []]]],
        )];
    }

    /**
     * @param array<mixed> $release
     */
    #[Test]
    #[DataProvider('consequencesLoweringTheirTracker')]
    public function itDoesNotWarnWhenTheForcedSceneTypeLowersTheTracker(array $release): void
    {
        self::assertSame([], ReleaseContent::fromArray($release)->warnings());
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function consequencesNotLoweringTheirTracker(): iterable
    {
        yield 'no tracker effect' => [self::firefightEffect(['kind' => 'sceneTitle', 'title' => 'Firefight'])];
        yield 'add a positive literal' => [self::firefightEffect(['kind' => 'tracker', 'tracker' => 'alarm', 'op' => 'add', 'value' => 1])];
        yield 'add a tracker value' => [self::firefightEffect(['kind' => 'tracker', 'tracker' => 'alarm', 'op' => 'add', 'value' => ['tracker' => 'edge']])];
        yield 'lower another tracker' => [self::firefightEffect(['kind' => 'tracker', 'tracker' => 'edge', 'op' => 'set', 'value' => 0])];
        yield 'the shipped fixture' => [ReleaseArrays::fixture('valid/v2-forced-scene-without-relief')];
    }

    /**
     * @param array<mixed> $release
     */
    #[Test]
    #[DataProvider('consequencesNotLoweringTheirTracker')]
    public function itWarnsWhenTheForcedSceneTypeDoesNotLowerTheTracker(array $release): void
    {
        self::assertSame([self::HEIST_WARNING], ReleaseContent::fromArray($release)->warnings());
    }

    /**
     * Only the forced Scene Type's own step effects count: an entry of a table it rolls lowers
     * the tracker only by chance (user decision after the slice 5 review).
     */
    #[Test]
    public function itWarnsWhenOnlyATableEntryTheForcedSceneTypeRollsLowersTheTracker(): void
    {
        $release = ReleaseArrays::with(
            self::firefightEffect(['kind' => 'sceneTitle', 'title' => 'Firefight']),
            'sceneTypes.2.play',
            [['key' => 'trouble', 'kind' => 'table', 'title' => 'What happens?', 'table' => 'complications']],
        );

        self::assertSame([self::HEIST_WARNING], ReleaseContent::fromArray($release)->warnings());
    }

    #[Test]
    public function itWarnsOnceAtTheFirstStepForcingASceneTypeTheConditionDecides(): void
    {
        $release = ReleaseArrays::with(
            self::firefightEffect(['kind' => 'sceneTitle', 'title' => 'Firefight']),
            self::WORLD_TURN.'.0.bands.1.effects',
            [['kind' => 'nextScene', 'sceneType' => 'firefight']],
        );

        self::assertSame(
            ['flows[0].phases[1].worldTurn[0]: nextScene firefight does not lower tracker alarm; the consequence may fire every turn'],
            ReleaseContent::fromArray($release)->warnings(),
        );
    }

    /**
     * Reached effects compare by Scene Type, not by path: each band forcing the same Scene Type
     * (its own effect, or different steps) decides nothing (slice 7 review).
     *
     * @return iterable<string, array{array<mixed>}>
     */
    public static function everyBandForcingTheSameSceneType(): iterable
    {
        $release = self::firefightEffect(['kind' => 'sceneTitle', 'title' => 'Firefight']);
        $forced = [['kind' => 'nextScene', 'sceneType' => 'firefight']];

        yield 'own effects' => [ReleaseArrays::with($release, self::WORLD_TURN, [
            ['key' => 'response', 'kind' => 'condition', 'title' => 'Does security respond?', 'tracker' => 'alarm', 'bands' => [['upTo' => 3, 'effects' => $forced], ['effects' => $forced]]],
        ])];
        yield 'different steps' => [ReleaseArrays::with($release, self::WORLD_TURN.'.0.bands.0', ['upTo' => 3, 'next' => 'end', 'effects' => $forced])];
    }

    /**
     * @param array<mixed> $release
     */
    #[Test]
    #[DataProvider('everyBandForcingTheSameSceneType')]
    public function itDoesNotWarnWhenEveryBandForcesTheSameSceneType(array $release): void
    {
        self::assertSame([], ReleaseContent::fromArray($release)->warnings());
    }

    #[Test]
    public function itDoesNotWarnWhenTheForcedSceneComesBeforeTheCondition(): void
    {
        $release = self::firefightEffect(['kind' => 'sceneTitle', 'title' => 'Firefight']);
        $release = ReleaseArrays::with($release, self::WORLD_TURN, [
            ['key' => 'guards', 'kind' => 'roll', 'title' => 'Guards', 'dice' => '1d6', 'bands' => [['upTo' => 3, 'effects' => [['kind' => 'nextScene', 'sceneType' => 'firefight']]], []]],
            ['key' => 'response', 'kind' => 'condition', 'title' => 'Does security respond?', 'tracker' => 'alarm', 'bands' => [['upTo' => 3, 'next' => 'end'], []]],
        ]);

        self::assertSame([], ReleaseContent::fromArray($release)->warnings());
    }

    #[Test]
    public function itDoesNotWarnForAForcedSceneEveryBandOfTheConditionReaches(): void
    {
        $release = self::firefightEffect(['kind' => 'sceneTitle', 'title' => 'Firefight']);
        $release = ReleaseArrays::with($release, self::WORLD_TURN.'.0.bands.0', ['upTo' => 3]);

        self::assertSame([], ReleaseContent::fromArray($release)->warnings());
    }

    #[Test]
    public function itWarnsWhenABandJumpsOverTheForcedScene(): void
    {
        $release = self::firefightEffect(['kind' => 'sceneTitle', 'title' => 'Firefight']);
        $release = ReleaseArrays::with($release, self::WORLD_TURN.'.0.bands.0.next', 'quiet');
        $release = ReleaseArrays::with($release, self::WORLD_TURN.'.2', ['key' => 'quiet', 'kind' => 'prompt', 'title' => 'Quiet night']);

        self::assertSame([self::HEIST_WARNING], ReleaseContent::fromArray($release)->warnings());
    }

    /**
     * Two thresholds in one world turn (the VtM example, fixed-threshold masquerade): each forced
     * Scene Type depends only on its own condition, whichever comes first (slice 6 review).
     *
     * @return iterable<string, array{array<mixed>}>
     */
    public static function twoThresholdsInOneWorldTurn(): iterable
    {
        $release = ReleaseArrays::fixture('examples/vtm-chronicle');
        $worldTurn = self::vtmWorldTurn($release);
        self::assertSame('masquerade', $worldTurn[0]['tracker'] ?? null);

        yield 'masquerade first' => [$release];
        yield 'masquerade last' => [ReleaseArrays::with($release, 'flows.0.phases.1.worldTurn', [...\array_slice($worldTurn, 1), $worldTurn[0]])];
    }

    /**
     * @param array<mixed> $release
     */
    #[Test]
    #[DataProvider('twoThresholdsInOneWorldTurn')]
    public function itDoesNotWarnForTwoThresholdsInOneStepList(array $release): void
    {
        self::assertSame([], ReleaseContent::fromArray($release)->warnings());
    }

    /**
     * @param array<mixed> $release
     */
    #[Test]
    #[DataProvider('twoThresholdsInOneWorldTurn')]
    public function itWarnsOnlyForTheConditionOfAForcedSceneThatDoesNotLowerIt(array $release): void
    {
        $release = ReleaseArrays::with($release, 'sceneTypes.11.closing.0.effects', []);
        $index = array_search('inquisition', array_column(self::vtmWorldTurn($release), 'key'), true);

        self::assertSame(
            [\sprintf('flows[0].phases[1].worldTurn[%d]: nextScene inquisition-raid does not lower tracker masquerade; the consequence may fire every turn', $index)],
            ReleaseContent::fromArray($release)->warnings(),
        );
    }

    /**
     * @param array<mixed> $release
     *
     * @return list<array<string, mixed>>
     */
    private static function vtmWorldTurn(array $release): array
    {
        /** @var array{flows: list<array{phases: list<array{worldTurn: list<array<string, mixed>>}>}>} $typed */
        $typed = $release;

        return $typed['flows'][0]['phases'][1]['worldTurn'];
    }

    #[Test]
    public function itDoesNotWarnAcrossStepLists(): void
    {
        $release = self::firefightEffect(['kind' => 'sceneTitle', 'title' => 'Firefight']);
        $release = ReleaseArrays::with($release, self::WORLD_TURN, [['key' => 'quiet', 'kind' => 'prompt', 'title' => 'Quiet night']]);
        $release = ReleaseArrays::with($release, 'flows.0.phases.1.sceneClosing.0.bands.1.effects', [['kind' => 'endPhase']]);
        $release = ReleaseArrays::with($release, 'flows.0.phases.1.phaseClosing', [
            ['key' => 'last-stand', 'kind' => 'prompt', 'title' => 'Last stand', 'effects' => [['kind' => 'nextScene', 'sceneType' => 'firefight']]],
        ]);

        self::assertSame([], ReleaseContent::fromArray($release)->warnings());
    }

    #[Test]
    public function itDoesNotWarnForASwitchInPlace(): void
    {
        $release = self::firefightEffect(['kind' => 'sceneTitle', 'title' => 'Firefight']);
        $release = ReleaseArrays::with($release, self::WORLD_TURN.'.1.bands.0.effects.0.kind', 'switchSceneType');

        self::assertSame([], ReleaseContent::fromArray($release)->warnings());
    }

    #[Test]
    public function itWarnsInSceneTypeStepLists(): void
    {
        $release = self::firefightEffect(['kind' => 'sceneTitle', 'title' => 'Firefight']);
        $release = ReleaseArrays::with($release, self::WORLD_TURN, []);
        $release = ReleaseArrays::with($release, 'sceneTypes.0.play', [
            ['key' => 'watch', 'kind' => 'condition', 'title' => 'Watched?', 'tracker' => 'alarm', 'bands' => [['upTo' => 2, 'next' => 'end'], []]],
            ['key' => 'chase', 'kind' => 'choice', 'title' => 'Run?', 'mandatory' => true, 'options' => [
                ['key' => 'run', 'label' => 'Run'],
                ['key' => 'fight', 'label' => 'Fight', 'effects' => [['kind' => 'nextScene', 'sceneType' => 'firefight']]],
            ]],
        ]);

        self::assertSame(
            ['sceneTypes[0].play[1]: nextScene firefight does not lower tracker alarm; the consequence may fire every turn'],
            ReleaseContent::fromArray($release)->warnings(),
        );
    }

    #[Test]
    public function itOrdersWarningsByPhaseHooksThenSceneTypeStepLists(): void
    {
        $release = self::firefightEffect(['kind' => 'sceneTitle', 'title' => 'Firefight']);
        $release = ReleaseArrays::with($release, 'sceneTypes.0.play', [
            ['key' => 'watch', 'kind' => 'condition', 'title' => 'Watched?', 'tracker' => 'alarm', 'bands' => [['upTo' => 2, 'effects' => [['kind' => 'nextScene', 'sceneType' => 'firefight']]], []]],
        ]);

        self::assertSame([
            self::HEIST_WARNING,
            'sceneTypes[0].play[0]: nextScene firefight does not lower tracker alarm; the consequence may fire every turn',
        ], ReleaseContent::fromArray($release)->warnings());
    }

    #[Test]
    public function itWarnsOncePerTrackerAndForcedScene(): void
    {
        $release = self::firefightEffect(['kind' => 'sceneTitle', 'title' => 'Firefight']);
        $release = ReleaseArrays::with($release, self::WORLD_TURN.'.1.kind', 'condition');
        $release = ReleaseArrays::with($release, self::WORLD_TURN.'.1.tracker', 'edge');
        $release = ReleaseArrays::without($release, self::WORLD_TURN.'.1.dice');

        self::assertSame([
            self::HEIST_WARNING,
            'flows[0].phases[1].worldTurn[1]: nextScene firefight does not lower tracker edge; the consequence may fire every turn',
        ], ReleaseContent::fromArray($release)->warnings());
    }

    #[Test]
    public function aVersion1ReleaseHasNoWarnings(): void
    {
        self::assertSame([], ReleaseContent::fromArray(ReleaseArrays::fixture('valid/contract-doc-example'))->warnings());
    }
}
