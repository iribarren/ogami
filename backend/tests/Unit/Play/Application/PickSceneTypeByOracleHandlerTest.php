<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignNotFound;
use App\Play\Application\PickSceneTypeByOracle;
use App\Play\Application\PickSceneTypeByOracleHandler;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\FlowRun\FlowRunNotActive;
use App\Play\Domain\Campaign\FlowRun\FlowRunPositionMismatch;
use App\Play\Domain\Campaign\FlowRun\SceneTypeNotOffered;
use App\Play\Domain\Campaign\NoCurrentSession;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Infrastructure\GameSystem\GameSystemReleaseTranslator;
use App\Tests\Support\Play\GuidedReleases;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(PickSceneTypeByOracle::class)]
#[CoversClass(PickSceneTypeByOracleHandler::class)]
final class PickSceneTypeByOracleHandlerTest extends FlowRunHandlerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->campaigns->add($this->guided('campaign-5', 'draw'));
        $this->campaigns->add($this->guided('campaign-6', 'tour'));
    }

    #[Test]
    public function itRollsTheScenePicksTableAndStartsTheSceneOfTheEntryWithoutAJournalEntry(): void
    {
        $this->pick(new PickSceneTypeByOracle('campaign-5', 'user-1'), 3);

        self::assertSame(['Tour 1', 'tour'], [$this->stored('campaign-5')->currentScene()?->title(), $this->stored('campaign-5')->currentScene()?->sceneType()]);
        self::assertSame([], $this->journalOf('campaign-5'));
    }

    #[Test]
    public function aScenePickThatDoesNotRollOnATableStartsNothing(): void
    {
        $before = $this->stored('campaign-6');

        try {
            $this->pick(new PickSceneTypeByOracle('campaign-6', 'user-1'));
            self::fail('A table was rolled for a pick by hand.');
        } catch (SceneTypeNotOffered) {
            self::assertEquals($before, $this->stored('campaign-6'));
        }
    }

    #[Test]
    public function anEntryThatNamesNoSceneTypeStartsNothing(): void
    {
        // Studio refuses such a table for a scene pick, so the release is changed after publishing.
        /** @var array<string, mixed> $content */
        $content = array_replace_recursive(GuidedReleases::content(), ['oracles' => ['tables' => [['entries' => [['max' => 5], ['min' => 6, 'max' => 6, 'text' => 'A quiet moment']]]]]]);
        $this->releases->add(new GameSystemReleaseTranslator()->translate(ReleaseViews::of($content)));
        $before = $this->stored('campaign-5');

        try {
            $this->pick(new PickSceneTypeByOracle('campaign-5', 'user-1'), 6);
            self::fail('A scene started from an entry without a Scene Type.');
        } catch (SceneTypeNotOffered $exception) {
            self::assertSame('The rolled entry of table "scene-kinds" names no Scene Type.', $exception->getMessage());
            self::assertEquals($before, $this->stored('campaign-5'));
        }
    }

    #[Test]
    public function aFlowRunWaitingForASessionStartsNothing(): void
    {
        $campaign = Campaign::create(CampaignId::fromString('campaign-7'), 'user-1', 'Waiting', PinnedRelease::of('guided', 1, 'Guided'), self::at('09:00'), [], $this->release->flow('draw'));
        $this->campaigns->add($campaign);
        $before = $this->stored('campaign-7');

        try {
            $this->pick(new PickSceneTypeByOracle('campaign-7', 'user-1'), 3);
            self::fail('A scene started without a session.');
        } catch (NoCurrentSession) {
            self::assertEquals($before, $this->stored('campaign-7'));
        }
    }

    #[Test]
    public function pausedGuidanceStartsNothing(): void
    {
        $campaign = $this->stored('campaign-5');
        $campaign->pauseGuidance(self::at('09:10'));
        $this->campaigns->save($campaign);
        $before = $this->stored('campaign-5');

        try {
            $this->pick(new PickSceneTypeByOracle('campaign-5', 'user-1'), 3);
            self::fail('A scene started while guidance was paused.');
        } catch (FlowRunNotActive) {
            self::assertEquals($before, $this->stored('campaign-5'));
        }
    }

    #[Test]
    public function aFlowRunInASceneIsNotAtTheScenePick(): void
    {
        $this->expectException(FlowRunPositionMismatch::class);
        $this->expectExceptionMessageIsOrContains('The FlowRun is at no scene pick, not at the scene pick.');

        $this->pick(new PickSceneTypeByOracle('campaign-1', 'user-1'));
    }

    #[Test]
    public function aFreeCampaignHasNoScenePick(): void
    {
        $this->expectException(FlowRunNotActive::class);

        $this->pick(new PickSceneTypeByOracle('campaign-2', 'user-1'));
    }

    #[Test]
    public function anotherPlayersCampaignIsNotFound(): void
    {
        $this->expectException(CampaignNotFound::class);

        $this->pick(new PickSceneTypeByOracle('campaign-5', 'user-2'), 3);
    }

    private function pick(PickSceneTypeByOracle $command, int ...$rolls): void
    {
        new PickSceneTypeByOracleHandler($this->ownedCampaigns, $this->campaigns, $this->releases, new ScriptedRandomNumberGenerator(...$rolls), $this->clock)($command);
    }
}
