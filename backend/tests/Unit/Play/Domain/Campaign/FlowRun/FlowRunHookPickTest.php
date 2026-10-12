<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunStage;
use App\Play\Domain\Campaign\FlowRun\PickedItem;
use App\Play\Domain\Campaign\FlowRun\PickedItems;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\Campaign\Hook;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * A pick step in a hook: the hook Scene is under way when the step waits (it starts at the first
 * step that waits), so what is picked belongs to it and "{picked}" survives the step's own Effects.
 * The test release has a world turn that picks a rival NPC and names the hook Scene after the pick.
 */
#[CoversClass(FlowRun::class)]
final class FlowRunHookPickTest extends FlowRunTestCase
{
    private GameSystemSnapshot $release;

    protected function setUp(): void
    {
        $this->release = self::translate($this->content());
    }

    #[Test]
    public function aPickInAHookIsPickedInTheHookSceneAndItsOwnEffectsRenderIt(): void
    {
        $campaign = $this->atTheWorldTurnPick();

        self::assertSame([FlowRunStage::Hook, Hook::WorldTurn, 'who'], [self::flowRunOf($campaign)->stage(), self::flowRunOf($campaign)->hook(), self::flowRunOf($campaign)->stepKey()]);
        self::assertSame(2, $campaign->currentScene()?->number(), 'The hook Scene is under way while the pick waits.');

        $campaign->completeFlowStep('who', StepResult::pick(ItemKind::Npc, [new PickedItem('ana', 'Ana')]), $this->release, self::at());

        self::assertSame('Rivals: Ana', $campaign->currentScene()?->title());
        self::assertEquals(new PickedItems(ItemKind::Npc, ['ana']), self::flowRunOf($campaign)->picked());
        self::assertSame([2, 2], [$campaign->currentScene()->number(), self::flowRunOf($campaign)->scene()[1] ?? null]);
    }

    #[Test]
    public function thePickLeavesWithTheHookSceneWhenTheNextSceneStarts(): void
    {
        $campaign = $this->atTheWorldTurnPick();
        $campaign->completeFlowStep('who', StepResult::pick(ItemKind::Npc, [new PickedItem('ana', 'Ana')]), $this->release, self::at());
        $campaign->completeFlowStep('after', self::prompt('ok'), $this->release, self::at());

        self::assertSame([FlowRunStage::Scene, null], [self::flowRunOf($campaign)->stage(), self::flowRunOf($campaign)->picked()]);
    }

    private function atTheWorldTurnPick(): Campaign
    {
        $campaign = self::guided($this->release, 'turns');
        $campaign->startSession(self::at(), $this->release);
        $campaign->addNpc(NpcId::fromString('ana'), 'Ana', null, ['rival'], [], 1, $this->release);
        $campaign->completeFlowStep('quiet', self::prompt('ok'), $this->release, self::at());
        $campaign->endFlowScene(1, $this->release, self::at());

        return $campaign;
    }

    /**
     * @return array<string, mixed>
     */
    private function content(): array
    {
        $prompt = static fn (string $key, string $title, array $fields = []): array => ['key' => $key, 'kind' => 'prompt', 'title' => $title] + $fields;

        return [
            'schemaVersion' => 2,
            'gameSystem' => ['key' => 'hook-pick', 'name' => 'Hook pick'],
            'oracles' => ['tables' => [], 'likelihood' => []],
            'trackers' => [],
            'factSlots' => [],
            'tags' => [['key' => 'rival', 'label' => 'Rival']],
            'sceneTypes' => [['key' => 'quiet', 'name' => 'Quiet', 'purpose' => 'A quiet scene.', 'oracles' => [], 'setup' => [$prompt('quiet', 'Quiet')], 'play' => [], 'closing' => []]],
            'flows' => [['key' => 'turns', 'name' => 'Turns', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => [], 'phases' => [
                ['key' => 'roam', 'name' => 'Roaming', 'mode' => 'loop', 'selection' => ['rule' => 'player', 'sceneTypes' => ['quiet']],
                    'worldTurn' => [
                        ['key' => 'who', 'kind' => 'pick', 'title' => 'Who stirs?', 'of' => 'npc', 'tags' => ['rival'], 'mode' => 'choose', 'effects' => [['kind' => 'sceneTitle', 'title' => 'Rivals: {picked}']]],
                        $prompt('after', 'Then {picked}'),
                    ],
                ],
            ]]],
            'checks' => [],
        ];
    }
}
