<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Tests\Support\Play\ReleaseViews;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * A release with fact slots, fillFact Effects and {fact:key} placeholders plays without error
 * before Campaign facts are applied (slice 4 of play-campaign-facts): fillFact changes nothing and
 * {fact:key} renders empty. Run on valid/v2-campaign-facts with one flow playing its Scene Type.
 */
#[CoversClass(FlowRun::class)]
final class FlowRunFactsTest extends FlowRunTestCase
{
    private function snapshot(): GameSystemSnapshot
    {
        $content = ReleaseViews::fixtureContent('valid/v2-campaign-facts');
        $content['flows'] = [['key' => 'facts', 'name' => 'Facts', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => [], 'phases' => [
            ['key' => 'roam', 'name' => 'Roaming', 'mode' => 'loop', 'selection' => ['rule' => 'player', 'sceneTypes' => ['scene']]],
        ]]];

        return self::translate($content);
    }

    private function inScene(): Campaign
    {
        $release = $this->snapshot();
        $campaign = self::guided($release, 'facts');
        $campaign->startSession(self::at(), $release);

        return $campaign;
    }

    #[Test]
    public function aStepWithAFillFactEffectCompletesAndChangesNothing(): void
    {
        $release = $this->snapshot();
        $campaign = $this->inScene();
        $before = [$campaign->npcs(), $campaign->threads(), self::history($campaign)];

        $campaign->completeFlowStep('city', self::prompt('Prague'), $release, self::at());

        self::assertSame(['play', 'prince'], self::position($campaign));
        self::assertSame($before, [$campaign->npcs(), $campaign->threads(), self::history($campaign)], 'fillFact creates, answers and records nothing.');
        self::assertSame(['city' => 'Prague'], self::flowRunOf($campaign)->answers());
    }

    #[Test]
    public function aFactPlaceholderRendersEmptyInViewsAndEffectText(): void
    {
        $release = $this->snapshot();
        $campaign = $this->inScene();
        $campaign->completeFlowStep('city', self::prompt('Prague'), $release, self::at());

        self::assertSame('Rule of ', $campaign->flowRunView($release)?->step?->title);

        $campaign->completeFlowStep('prince', self::prompt('Vlad'), $release, self::at());

        self::assertSame(['Prince of'], array_map(static fn (\App\Play\Domain\Campaign\Lists\Npc $npc): string => $npc->name(), $campaign->npcs()));
        self::assertSame('Ambition', $campaign->flowRunView($release)?->step?->title);
    }
}
