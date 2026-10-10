<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\Hook;
use App\Play\Domain\Campaign\HookSceneHasNoSceneType;
use App\Play\Domain\Campaign\InvalidSceneTitle;
use App\Play\Domain\Campaign\NoCurrentScene;
use App\Play\Domain\Campaign\NoCurrentSession;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\Campaign\Scene;
use App\Play\Domain\Campaign\SceneKind;
use App\Play\Domain\Campaign\Session;
use App\Play\Domain\GameSystem\Flow\Phase;
use App\Play\Domain\GameSystem\Flow\PhaseMode;
use App\Play\Domain\GameSystem\Flow\SceneSelection;
use App\Play\Domain\GameSystem\Flow\StepList;
use App\Play\Domain\GameSystem\SceneType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Scenes of play with an optional Scene Type, hook Scenes, default titles and switching a Scene
 * Type by hand.
 */
#[CoversClass(Campaign::class)]
#[CoversClass(Session::class)]
#[CoversClass(Scene::class)]
#[CoversClass(Hook::class)]
#[CoversClass(HookSceneHasNoSceneType::class)]
#[CoversClass(NoCurrentScene::class)]
#[CoversClass(InvalidSceneTitle::class)]
final class CampaignScenesTest extends TestCase
{
    #[Test]
    public function aSceneWithoutATitleIsNamedAfterItsSceneTypeNumberedPerTypeAcrossTheCampaign(): void
    {
        $campaign = $this->campaignInASession();
        $legwork = $this->sceneType('legwork', 'Legwork');
        $firefight = $this->sceneType('firefight', 'Firefight');

        $first = $campaign->startScene(null, new \DateTimeImmutable(), $legwork);
        $campaign->startScene(null, new \DateTimeImmutable(), $firefight);
        $campaign->startScene('A quiet drink', new \DateTimeImmutable());
        $campaign->startSession(new \DateTimeImmutable());
        $second = $campaign->startScene(null, new \DateTimeImmutable(), $legwork);

        self::assertSame(['Legwork 1', 'legwork', SceneKind::Scene, null], [$first->title(), $first->sceneType(), $first->kind(), $first->hook()]);
        self::assertSame(['Legwork 1', 'Firefight 1', 'A quiet drink'], $this->titles($campaign->sessions()[0]));
        self::assertSame(['Legwork 2', 'legwork'], [$second->title(), $second->sceneType()]);
    }

    #[Test]
    public function aTitleGivenWinsOverTheDefaultAndStillCounts(): void
    {
        $campaign = $this->campaignInASession();
        $legwork = $this->sceneType('legwork', 'Legwork');

        $named = $campaign->startScene('  Casing the bank ', new \DateTimeImmutable(), $legwork);
        $campaign->startScene(null, new \DateTimeImmutable(), $legwork);

        self::assertSame(['Casing the bank', 'legwork'], [$named->title(), $named->sceneType()]);
        self::assertSame(['Casing the bank', 'Legwork 2'], $this->titles($campaign->sessions()[0]));
    }

    #[Test]
    public function aSceneWithoutASceneTypeNeedsATitle(): void
    {
        $campaign = $this->campaignInASession();

        $this->expectException(InvalidSceneTitle::class);
        $this->expectExceptionMessageIsOrContains('A scene without a Scene Type needs a title.');

        $campaign->startScene(null, new \DateTimeImmutable());
    }

    #[Test]
    public function aLongSceneTypeNameIsCutSoTheNumberFits(): void
    {
        $campaign = $this->campaignInASession();

        $scene = $campaign->startScene(null, new \DateTimeImmutable(), $this->sceneType('long', str_repeat('é', 100)));

        self::assertSame(str_repeat('é', 98).' 1', $scene->title());
    }

    #[Test]
    public function aHookSceneRecordsItsHookAndHasNoSceneType(): void
    {
        $campaign = $this->campaignInASession();
        $campaign->startScene('At the gate', new \DateTimeImmutable());

        $scene = $campaign->startHookScene(Hook::WorldTurn, ' The world moves ', new \DateTimeImmutable('2026-10-10 10:00:00'));

        self::assertSame([2, 'The world moves', SceneKind::Hook, null, Hook::WorldTurn], [$scene->number(), $scene->title(), $scene->kind(), $scene->sceneType(), $scene->hook()]);
        self::assertSame($scene, $campaign->currentScene());
        self::assertEquals(new \DateTimeImmutable('2026-10-10 10:00:00'), $scene->startedAt());
    }

    #[Test]
    public function aHookSceneNeedsASessionAndAValidTitle(): void
    {
        $campaign = $this->campaign();
        try {
            $campaign->startHookScene(Hook::SessionOpening, 'Session 1 begins', new \DateTimeImmutable());
            self::fail('A hook scene started without a session.');
        } catch (NoCurrentSession) {
        }

        $campaign->startSession(new \DateTimeImmutable());
        $this->expectException(InvalidSceneTitle::class);

        $campaign->startHookScene(Hook::SessionOpening, '  ', new \DateTimeImmutable());
    }

    /**
     * @return iterable<string, array{Hook, ?string, string}>
     */
    public static function hookTitles(): iterable
    {
        yield 'session opening' => [Hook::SessionOpening, 'Act 2', 'Session 3 begins'];
        yield 'session closing' => [Hook::SessionClosing, 'Act 2', 'Session 3 ends'];
        yield 'phase opening in an act' => [Hook::PhaseOpening, 'Act 2', 'Act 2: The big job'];
        yield 'phase opening without an act' => [Hook::PhaseOpening, null, 'The big job'];
        yield 'phase closing' => [Hook::PhaseClosing, 'Act 2', 'The big job ends'];
        yield 'world turn' => [Hook::WorldTurn, 'Act 2', 'The world moves'];
    }

    #[Test]
    #[DataProvider('hookTitles')]
    public function eachHookHasItsDefaultTitle(Hook $hook, ?string $act, string $title): void
    {
        self::assertSame($title, $hook->defaultTitle(3, $this->phase('The big job', $act)));
    }

    #[Test]
    public function aLongHookTitleIsCutToTheLongestSceneTitle(): void
    {
        $title = Hook::PhaseOpening->defaultTitle(1, $this->phase(str_repeat('b', 100), str_repeat('a', 100)));

        self::assertSame(Scene::MAX_TITLE_LENGTH, mb_strlen($title));
        self::assertStringStartsWith(str_repeat('a', 100), $title);
    }

    #[Test]
    public function theCurrentSceneSwitchesItsSceneTypeKeepingItsNumberAndTitle(): void
    {
        $campaign = $this->campaignInASession();
        $campaign->startScene('At the gate', new \DateTimeImmutable());
        $campaign->startScene(null, new \DateTimeImmutable('2026-10-10 10:00:00'), $this->sceneType('legwork', 'Legwork'));

        $switched = $campaign->switchSceneType($this->sceneType('firefight', 'Firefight'));

        self::assertSame([2, 'Legwork 1', 'firefight', SceneKind::Scene], [$switched->number(), $switched->title(), $switched->sceneType(), $switched->kind()]);
        self::assertEquals(new \DateTimeImmutable('2026-10-10 10:00:00'), $switched->startedAt());
        self::assertSame($switched, $campaign->currentScene());
        self::assertSame(['At the gate', 'Legwork 1'], $this->titles($campaign->sessions()[0]));
        // The next Firefight counts the switched scene; the next Legwork does not.
        self::assertSame('Firefight 2', $campaign->startScene(null, new \DateTimeImmutable(), $this->sceneType('firefight', 'Firefight'))->title());
        self::assertSame('Legwork 1', $campaign->startScene(null, new \DateTimeImmutable(), $this->sceneType('legwork', 'Legwork'))->title());
    }

    #[Test]
    public function aSceneStartedWithoutASceneTypeGetsOneBySwitching(): void
    {
        $campaign = $this->campaignInASession();
        $campaign->startScene('At the gate', new \DateTimeImmutable());

        self::assertSame(['At the gate', 'legwork'], [$campaign->switchSceneType($this->sceneType('legwork', 'Legwork'))->title(), $campaign->currentScene()?->sceneType()]);
    }

    #[Test]
    public function aHookSceneCannotSwitchItsSceneType(): void
    {
        $campaign = $this->campaignInASession();
        $campaign->startHookScene(Hook::SessionOpening, 'Session 1 begins', new \DateTimeImmutable());

        try {
            $campaign->switchSceneType($this->sceneType('legwork', 'Legwork'));
            self::fail('A hook scene switched its Scene Type.');
        } catch (HookSceneHasNoSceneType $exception) {
            self::assertSame('The current scene is a "sessionOpening" hook scene: only a scene of play has a Scene Type to switch.', $exception->getMessage());
        }

        self::assertNull($campaign->currentScene()?->sceneType());
    }

    #[Test]
    public function aSceneOfKindHookCannotSwitchItsSceneTypeEvenWithoutAHookName(): void
    {
        $scene = Scene::reconstitute(1, 'Session 1 begins', new \DateTimeImmutable(), SceneKind::Hook);

        $this->expectException(HookSceneHasNoSceneType::class);
        $this->expectExceptionMessageIsOrContains('The current scene is a hook scene: only a scene of play has a Scene Type to switch.');

        $scene->withSceneType('legwork');
    }

    #[Test]
    public function switchingNeedsACurrentScene(): void
    {
        foreach ([$this->campaign(), $this->campaignInASession()] as $campaign) {
            try {
                $campaign->switchSceneType($this->sceneType('legwork', 'Legwork'));
                self::fail('A Scene Type was switched without a current scene.');
            } catch (NoCurrentScene $exception) {
                self::assertSame('Start a scene before switching its Scene Type.', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function aSceneStoredBeforeScenesHadAKindIsASceneOfPlayWithoutASceneType(): void
    {
        $scene = Scene::reconstitute(1, 'At the gate', new \DateTimeImmutable());

        self::assertSame([SceneKind::Scene, null, null], [$scene->kind(), $scene->sceneType(), $scene->hook()]);
    }

    private function campaign(): Campaign
    {
        return Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-10 09:00:00'));
    }

    private function campaignInASession(): Campaign
    {
        $campaign = $this->campaign();
        $campaign->startSession(new \DateTimeImmutable('2026-10-10 09:05:00'));

        return $campaign;
    }

    private function sceneType(string $key, string $name): SceneType
    {
        return new SceneType($key, $name, 'A purpose.', null, [], new StepList(), new StepList(), new StepList());
    }

    private function phase(string $name, ?string $act): Phase
    {
        return new Phase('phase', $name, $act, PhaseMode::Once, SceneSelection::player(['legwork']));
    }

    /**
     * @return list<string>
     */
    private function titles(Session $session): array
    {
        return array_map(static fn (Scene $scene): string => $scene->title(), $session->scenes());
    }
}
