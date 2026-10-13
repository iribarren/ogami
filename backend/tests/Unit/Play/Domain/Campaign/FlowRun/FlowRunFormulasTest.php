<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\FlowRun\FlowFormulaFailed;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunContext;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\Tracker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Formulas in band bounds and tracker Effect values (ADR 0023), on a test release with the counters
 * "limit" (2), "heat" (3), "zero" (0) and "alarm" (0 to 20): "gamble" rolls against the formula
 * limit + 2, "boost" adds max(limit, 3) to the alarm, and "broken" and "brokenBand" divide by zero.
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(FlowRunContext::class)]
#[CoversClass(FlowFormulaFailed::class)]
final class FlowRunFormulasTest extends FlowRunTestCase
{
    private GameSystemSnapshot $release;

    protected function setUp(): void
    {
        $this->release = self::translate($this->content());
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function rollTotals(): iterable
    {
        yield 'under the formula bound' => [3, 2, 'low'];
        yield 'on the formula bound' => [4, 2, 'low'];
        yield 'past the formula bound' => [5, 2, 'high'];
        yield 'on a formula bound raised by hand' => [5, 3, 'low'];
    }

    #[Test]
    #[DataProvider('rollTotals')]
    public function aRollStepBranchesByABandWhoseBoundIsAFormula(int $total, int $limit, string $next): void
    {
        $campaign = $this->inScene('gamble');
        $campaign->setTrackerValue($this->trackerOf('limit'), $limit);

        $campaign->completeFlowStep('gamble', self::roll('1d6', $total), $this->release, self::at());

        self::assertSame(['setup', $next], self::position($campaign));
    }

    #[Test]
    public function aTrackerEffectAddsTheValueOfAFormula(): void
    {
        $campaign = $this->inScene('boost');

        $campaign->completeFlowStep('boost', self::prompt('ok'), $this->release, self::at());

        self::assertSame(3, $this->tracker($campaign, 'alarm'), 'max(2, 3)');
    }

    #[Test]
    public function aFormulaEffectReadsTheTrackersAsTheyAreWhenItRuns(): void
    {
        $campaign = $this->inScene('boost');
        $campaign->setTrackerValue($this->trackerOf('limit'), 7);

        $campaign->completeFlowStep('boost', self::prompt('ok'), $this->release, self::at());

        self::assertSame(7, $this->tracker($campaign, 'alarm'), 'max(7, 3)');
    }

    #[Test]
    public function aDivisionByZeroInAnEffectFailsTheCommandAndChangesNothing(): void
    {
        $campaign = $this->inScene('broken');
        $before = clone $campaign;

        try {
            $campaign->completeFlowStep('broken', self::prompt('ok'), $this->release, self::at());
            self::fail('A division by zero was accepted.');
        } catch (FlowFormulaFailed $exception) {
            self::assertStringContainsString('{tracker:heat} / {tracker:zero}', $exception->getMessage());
            self::assertStringContainsString('divides by zero', $exception->getMessage());
            self::assertEquals($before, $campaign);
            self::assertSame([3, 0], [$this->tracker($campaign, 'heat'), $this->tracker($campaign, 'alarm')], 'The effect before the failing one is undone too.');
        }
    }

    #[Test]
    public function aDivisionByZeroInABandBoundFailsTheCommandAndChangesNothing(): void
    {
        $campaign = $this->inScene('brokenBand');
        $before = clone $campaign;

        $this->expectException(FlowFormulaFailed::class);

        try {
            $campaign->completeFlowStep('brokenBand', self::roll('1d6', 3), $this->release, self::at());
        } finally {
            self::assertEquals($before, $campaign);
        }
    }

    private function inScene(string $sceneType): Campaign
    {
        $campaign = self::guided($this->release, 'formulas');
        $campaign->startSession(self::at(), $this->release);
        $campaign->pickSceneType($sceneType, $this->release, self::at());

        return $campaign;
    }

    private function tracker(Campaign $campaign, string $key): int
    {
        return $campaign->trackerValue($this->trackerOf($key));
    }

    private function trackerOf(string $key): Tracker
    {
        return $this->release->tracker($key) ?? self::fail('No Tracker '.$key);
    }

    /**
     * @return array<string, mixed>
     */
    private function content(): array
    {
        $prompt = static fn (string $key, string $title, array $fields = []): array => ['key' => $key, 'kind' => 'prompt', 'title' => $title] + $fields;
        $sceneType = static fn (string $key, array $setup): array => ['key' => $key, 'name' => ucfirst($key), 'purpose' => 'A '.$key.' scene.', 'oracles' => [], 'setup' => $setup, 'play' => [], 'closing' => []];
        $tracker = static fn (string $key, string $op, int|array $value): array => ['kind' => 'tracker', 'tracker' => $key, 'op' => $op, 'value' => $value];
        $counter = static fn (string $key, int $max, int $initial): array => ['key' => $key, 'name' => ucfirst($key), 'kind' => 'counter', 'min' => 0, 'max' => $max, 'initial' => $initial];

        return [
            'schemaVersion' => 2,
            'gameSystem' => ['key' => 'formulas', 'name' => 'Formulas'],
            'oracles' => ['tables' => [], 'likelihood' => []],
            'trackers' => [$counter('limit', 9, 2), $counter('heat', 9, 3), $counter('zero', 9, 0), $counter('alarm', 20, 0)],
            'factSlots' => [],
            'sceneTypes' => [
                $sceneType('gamble', [
                    ['key' => 'gamble', 'kind' => 'roll', 'title' => 'Push your luck', 'dice' => '1d6', 'bands' => [['upTo' => ['formula' => '{tracker:limit} + 2'], 'next' => 'low'], ['next' => 'high']]],
                    $prompt('low', 'Low'), $prompt('high', 'High'),
                ]),
                $sceneType('boost', [$prompt('boost', 'Boost', ['effects' => [$tracker('alarm', 'add', ['formula' => 'max({tracker:limit}, 3)'])]])]),
                $sceneType('broken', [$prompt('broken', 'Broken', ['effects' => [$tracker('heat', 'add', 1), $tracker('alarm', 'add', ['formula' => '{tracker:heat} / {tracker:zero}'])]])]),
                $sceneType('brokenBand', [
                    ['key' => 'brokenBand', 'kind' => 'roll', 'title' => 'Broken', 'dice' => '1d6', 'bands' => [['upTo' => ['formula' => '1 / {tracker:zero}'], 'next' => 'low'], ['next' => 'high']]],
                    $prompt('low', 'Low'), $prompt('high', 'High'),
                ]),
            ],
            'flows' => [['key' => 'formulas', 'name' => 'Formulas', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => ['limit', 'heat', 'zero', 'alarm'], 'phases' => [
                ['key' => 'roam', 'name' => 'Roaming', 'mode' => 'loop', 'selection' => ['rule' => 'player', 'sceneTypes' => ['gamble', 'boost', 'broken', 'brokenBand']]],
            ]]],
            'checks' => [],
        ];
    }
}
