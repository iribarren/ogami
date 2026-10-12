<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\Hook;
use App\Play\Domain\Campaign\InvalidSceneCast;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\UnknownNpc;
use App\Play\Domain\Campaign\NoCurrentScene;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\Campaign\Scene;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A Scene's cast: an ordered list of the campaign's NPCs, without repeats, that the player edits
 * for the current scene.
 */
#[CoversClass(Campaign::class)]
#[CoversClass(Scene::class)]
#[CoversClass(InvalidSceneCast::class)]
final class CampaignSceneCastTest extends TestCase
{
    private GameSystemSnapshot $release;

    protected function setUp(): void
    {
        $this->release = new GameSystemSnapshot('heist', 'Heist', 1, null, [], []);
    }

    #[Test]
    public function aSceneStartsWithAnEmptyCast(): void
    {
        self::assertSame([], $this->campaignInAScene()->currentScene()?->cast());
        self::assertSame([], Scene::start(1, 'Gate', new \DateTimeImmutable())->cast());
    }

    #[Test]
    public function thePlayerReplacesTheCurrentScenesCastInOrderClosedNpcsIncluded(): void
    {
        $campaign = $this->campaignInAScene();
        $campaign->closeNpc(NpcId::fromString('cid'));

        $campaign->editCurrentSceneCast([$this->id('cid'), $this->id('ana')]);
        self::assertSame(['cid', 'ana'], $this->cast($campaign));

        $campaign->editCurrentSceneCast([$this->id('bea')]);
        self::assertSame(['bea'], $this->cast($campaign));

        $campaign->editCurrentSceneCast([]);
        self::assertSame([], $this->cast($campaign));
    }

    #[Test]
    public function theCastBelongsToItsSceneAndSurvivesRenamesAndClosingAnNpc(): void
    {
        $campaign = $this->campaignInAScene();
        $campaign->editCurrentSceneCast([$this->id('ana'), $this->id('bea')]);
        $campaign->startScene('Second', new \DateTimeImmutable());

        $campaign->editCurrentSceneCast([$this->id('cid')]);
        $campaign->renameCurrentScene('Renamed');
        $campaign->closeNpc(NpcId::fromString('cid'));

        $scenes = $campaign->currentSession()?->scenes() ?? [];
        self::assertSame([['ana', 'bea'], ['cid']], array_map(static fn (Scene $scene): array => array_map(static fn (NpcId $id): string => $id->toString(), $scene->cast()), $scenes));
        self::assertSame('Renamed', $campaign->currentScene()?->title());
    }

    #[Test]
    public function aHookSceneHasACastToo(): void
    {
        $campaign = $this->campaignInAScene();
        $campaign->startHookScene(Hook::WorldTurn, 'World turn', new \DateTimeImmutable());

        $campaign->editCurrentSceneCast([$this->id('ana')]);

        self::assertSame(['ana'], $this->cast($campaign));
    }

    #[Test]
    public function aCastThatBreaksTheRulesChangesNothing(): void
    {
        $campaign = $this->campaignInAScene();
        $campaign->editCurrentSceneCast([$this->id('ana')]);
        $before = clone $campaign;

        try {
            $campaign->editCurrentSceneCast([$this->id('bea'), $this->id('ghost')]);
            self::fail('An unknown NPC was accepted.');
        } catch (UnknownNpc $exception) {
            self::assertSame('NPC "ghost" not found.', $exception->getMessage());
        }

        try {
            $campaign->editCurrentSceneCast([$this->id('bea'), $this->id('ana'), $this->id('bea')]);
            self::fail('A repeated NPC was accepted.');
        } catch (InvalidSceneCast $exception) {
            self::assertSame('NPC "bea" is in the cast twice.', $exception->getMessage());
        }

        self::assertEquals($before, $campaign);
    }

    #[Test]
    public function withoutACurrentSceneThereIsNoCastToEdit(): void
    {
        $campaign = Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable());
        $campaign->addNpc(NpcId::fromString('ana'), 'Ana', null, [], [], 1, $this->release);

        foreach ([static fn (): null => null, static fn (Campaign $campaign): \App\Play\Domain\Campaign\Session => $campaign->startSession(new \DateTimeImmutable())] as $prepare) {
            $prepare($campaign);

            foreach ([static fn (Campaign $campaign): Scene => $campaign->editCurrentSceneCast([]), static fn (Campaign $campaign) => $campaign->addToCurrentSceneCast([NpcId::fromString('ana')])] as $command) {
                try {
                    $command($campaign);
                    self::fail('A cast was edited without a scene.');
                } catch (NoCurrentScene $exception) {
                    self::assertSame('Start a scene before editing its cast.', $exception->getMessage());
                }
            }
        }
    }

    #[Test]
    public function npcsJoinTheEndOfTheCastOnceEach(): void
    {
        $campaign = $this->campaignInAScene();
        $campaign->editCurrentSceneCast([$this->id('bea')]);

        $campaign->addToCurrentSceneCast([$this->id('ana'), $this->id('bea')]);
        $campaign->addToCurrentSceneCast([$this->id('ana'), $this->id('cid')]);

        self::assertSame(['bea', 'ana', 'cid'], $this->cast($campaign));
    }

    #[Test]
    public function aStoredCastListingAnNpcTwiceIsRefused(): void
    {
        $this->expectException(InvalidSceneCast::class);

        Scene::reconstitute(1, 'Gate', new \DateTimeImmutable(), cast: [$this->id('ana'), $this->id('ana')]);
    }

    private function campaignInAScene(): Campaign
    {
        $campaign = Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable());
        foreach (['ana', 'bea', 'cid'] as $id) {
            $campaign->addNpc(NpcId::fromString($id), ucfirst($id), null, [], [], 1, $this->release);
        }

        $campaign->startSession(new \DateTimeImmutable());
        $campaign->startScene('At the gate', new \DateTimeImmutable());

        return $campaign;
    }

    private function id(string $id): NpcId
    {
        return NpcId::fromString($id);
    }

    /**
     * @return list<string> the current scene's cast, as ids
     */
    private function cast(Campaign $campaign): array
    {
        return array_map(static fn (NpcId $id): string => $id->toString(), $campaign->currentScene()?->cast() ?? []);
    }
}
