<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\InvalidStepResult;
use App\Play\Domain\GameSystem\Flow\TableStep;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * A table step that rolls a table by Scene Type (ADR 0042). The step "trouble" rolls "any" by
 * default and "wild" in a Scene of the Scene Type "wild"; the Scene Types are "town" (the map leaves
 * it out) and "wild", and the world turn (a hook, no Scene Type) asks the same step.
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(TableStep::class)]
final class FlowRunTableBySceneTypeTest extends FlowRunTestCase
{
    private GameSystemSnapshot $release;

    protected function setUp(): void
    {
        $this->release = self::translate($this->content());
    }

    #[Test]
    public function aSceneTypeThatTheMapLeavesOutRollsTheDefaultTable(): void
    {
        $campaign = $this->inScene('town');

        self::assertSame('any', $this->viewedTable($campaign));
        $campaign->completeFlowStep('trouble', self::table('any', 'A rumour'), $this->release, self::at());

        self::assertSame('A rumour', self::flowRunOf($campaign)->answers()['trouble']);
    }

    #[Test]
    public function aMappedSceneTypeRollsItsTableAndRefusesTheOthersWithNothingChanged(): void
    {
        $campaign = $this->inScene('wild');
        $before = clone $campaign;

        try {
            $campaign->completeFlowStep('trouble', self::table('any', 'A rumour'), $this->release, self::at());
            self::fail('The default table was accepted.');
        } catch (InvalidStepResult $e) {
            self::assertSame('Step "trouble" rolls on table "wild".', $e->getMessage());
        }

        self::assertEquals($before, $campaign);
        self::assertSame('wild', $this->viewedTable($campaign));
        $campaign->completeFlowStep('trouble', self::table('wild', 'A storm'), $this->release, self::at());
        self::assertSame('A storm', self::flowRunOf($campaign)->answers()['trouble']);
    }

    #[Test]
    public function switchingTheSceneTypeMidSceneChangesTheTableOfLaterRolls(): void
    {
        $campaign = $this->inScene('town');
        self::assertSame('any', $this->viewedTable($campaign));

        $campaign->switchSceneType($this->release->sceneType('wild') ?? self::fail('No wild.'), $this->release, self::at());

        self::assertSame('wild', $this->viewedTable($campaign));
        $campaign->completeFlowStep('trouble', self::table('wild', 'A wolf', 2), $this->release, self::at());
        self::assertSame('A wolf', self::flowRunOf($campaign)->answers()['trouble']);
    }

    #[Test]
    public function aHookWithoutASceneTypeRollsTheDefaultTable(): void
    {
        $campaign = $this->inScene('wild');
        $campaign->completeFlowStep('trouble', self::table('wild', 'A storm'), $this->release, self::at());
        $campaign->endFlowScene(1, $this->release, self::at());

        self::assertSame([null, 'trouble', 'any'], [self::flowRunOf($campaign)->sceneType(), self::flowRunOf($campaign)->stepKey(), $this->viewedTable($campaign)]);
        $campaign->completeFlowStep('trouble', self::table('any', 'A stranger', 2), $this->release, self::at());
        self::assertSame('A stranger', self::flowRunOf($campaign)->answers()['trouble']);
    }

    private function viewedTable(Campaign $campaign): ?string
    {
        return $campaign->flowRunView($this->release)?->step?->table;
    }

    private function inScene(string $sceneType): Campaign
    {
        $campaign = self::guided($this->release, 'play');
        $campaign->startSession(self::at(), $this->release);
        $campaign->pickSceneType($sceneType, $this->release, self::at());
        self::assertSame($sceneType, self::flowRunOf($campaign)->sceneType());

        return $campaign;
    }

    /**
     * @return array<string, mixed>
     */
    private function content(): array
    {
        $table = static fn (string $key, string $first, string $second): array => ['key' => $key, 'name' => ucfirst($key), 'entries' => [['text' => $first], ['text' => $second]]];
        $trouble = ['key' => 'trouble', 'kind' => 'table', 'title' => 'Trouble', 'table' => 'any', 'tableBySceneType' => ['wild' => 'wild']];
        $sceneType = static fn (string $key, array $play): array => ['key' => $key, 'name' => ucfirst($key), 'purpose' => 'A '.$key.' scene.', 'oracles' => [], 'setup' => [], 'play' => $play, 'closing' => []];

        return [
            'schemaVersion' => 2,
            'gameSystem' => ['key' => 'tables', 'name' => 'Tables'],
            'oracles' => ['tables' => [$table('any', 'A rumour', 'A stranger'), $table('wild', 'A storm', 'A wolf')], 'likelihood' => []],
            'trackers' => [],
            'factSlots' => [],
            'sceneTypes' => [$sceneType('town', [$trouble]), $sceneType('wild', [$trouble])],
            'flows' => [['key' => 'play', 'name' => 'Play', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => [], 'phases' => [
                ['key' => 'roam', 'name' => 'Roaming', 'mode' => 'loop', 'selection' => ['rule' => 'player', 'sceneTypes' => ['town', 'wild']], 'worldTurn' => [$trouble]],
            ]]],
            'checks' => [],
        ];
    }
}
