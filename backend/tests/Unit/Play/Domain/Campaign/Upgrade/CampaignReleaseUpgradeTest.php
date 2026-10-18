<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\Upgrade;

use App\Formula\Domain\Formula;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\Characters\Character;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\Campaign\Facts\Fact;
use App\Play\Domain\Campaign\Facts\FactFill;
use App\Play\Domain\Campaign\Facts\FactId;
use App\Play\Domain\Campaign\Facts\FactLink;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunStage;
use App\Play\Domain\Campaign\FlowRun\FlowRunStatus;
use App\Play\Domain\Campaign\Lists\Npc;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\Thread;
use App\Play\Domain\Campaign\Lists\ThreadId;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\Campaign\Scene;
use App\Play\Domain\Campaign\Upgrade\CampaignUpToDate;
use App\Play\Domain\Campaign\Upgrade\ReleaseUpgradeChanged;
use App\Play\Domain\Campaign\Upgrade\UpgradeToAnotherGameSystem;
use App\Play\Domain\GameSystem\FactSlot;
use App\Play\Domain\GameSystem\FactSlotType;
use App\Play\Domain\GameSystem\Flow\StepList;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\SceneType;
use App\Play\Domain\GameSystem\Sheet\DerivedField;
use App\Play\Domain\GameSystem\Sheet\ListField;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\TextField;
use App\Play\Domain\GameSystem\Sheet\TrackField;
use App\Play\Domain\GameSystem\Tracker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Upgrading a campaign applies exactly the confirmed plan, all or nothing, and re-pins it (ADR 0035).
 */
#[CoversClass(Campaign::class)]
final class CampaignReleaseUpgradeTest extends TestCase
{
    private \DateTimeImmutable $at;

    protected function setUp(): void
    {
        $this->at = new \DateTimeImmutable('2026-10-18T10:00:00+00:00');
    }

    #[Test]
    public function theUpgradeFitsEveryStoredValueAndRepinsTheCampaign(): void
    {
        [$pinned, $target, $campaign] = $this->scenario();
        $fingerprint = $campaign->planReleaseUpgrade($pinned, $target)->fingerprint();

        $campaign->upgradeRelease($pinned, $target, $fingerprint, $this->at);

        $heat = Tracker::counter('heat', 'Heat', null, 0, 6, 2);
        $expected = Campaign::reconstitute(
            CampaignId::fromString('campaign-1'),
            'user-1',
            'The job',
            PinnedRelease::of('heist', 2, 'Heist'),
            new \DateTimeImmutable('2026-10-12T09:00:00+00:00'),
            [],
            [$heat->key => 6],
            'main',
            FlowRun::start(),
            [
                Npc::of(NpcId::fromString('npc-1'), 'Mira', null, ['ally'], ['rival'], 1),
                Npc::of(NpcId::fromString('npc-2'), 'Mithras', null, [], [], 1),
            ],
            [Thread::of(ThreadId::fromString('thread-1'), 'Vault', [], ['boss'], 1)],
            [
                Fact::of(FactId::fromString('fact-1'), null, 'City', 'Night City', null, []),
                Fact::of(FactId::fromString('fact-2'), null, 'The Prince', 'Mithras', null, [FactLink::npc(NpcId::fromString('npc-2'))]),
                Fact::of(FactId::fromString('fact-3'), 'goal', null, 'Rob the vault', null, []),
            ],
            [Character::of(CharacterId::fromString('c-1'), 'Vi', ['ally'], ['boss'], ['luck' => 3, 'hp' => ['current' => 10, 'max' => 10], 'gear' => ['a', 'b'], 'nerve' => 5, 'role' => ''])],
        );
        self::assertEquals($expected, $campaign);
        self::assertSame(2, $campaign->pinnedRelease()->releaseVersion());
    }

    #[Test]
    public function aSlotFactThatHoldsASubjectKeepsItAsALinkAndItsNameAsText(): void
    {
        $slots = [new FactSlot('prince', 'The Prince', FactSlotType::Npc)];
        $pinned = UpgradeFixtures::release(1, factSlots: $slots);
        $campaign = UpgradeFixtures::campaign($pinned);
        $campaign->fillFactSlot(FactId::fromString('fact-1'), 'prince', FactFill::newNpc(NpcId::fromString('npc-1'), 'Mithras'), $pinned);
        $target = UpgradeFixtures::release(2);

        $campaign->upgradeRelease($pinned, $target, $campaign->planReleaseUpgrade($pinned, $target)->fingerprint(), $this->at);

        $fact = $campaign->fact(FactId::fromString('fact-1'));
        self::assertInstanceOf(Fact::class, $fact);
        self::assertSame([null, 'The Prince', 'Mithras', null], [$fact->slot(), $fact->label(), $fact->text(), $fact->subject()]);
        self::assertEquals([FactLink::npc(NpcId::fromString('npc-1'))], $fact->links());
    }

    #[Test]
    public function aFingerprintThatIsNotThePlanChangesNothing(): void
    {
        [$pinned, $target, $campaign] = $this->scenario();
        $before = clone $campaign;

        try {
            $campaign->upgradeRelease($pinned, $target, str_repeat('0', 64), $this->at);
            self::fail('The upgrade should be refused.');
        } catch (ReleaseUpgradeChanged) {
            self::assertEquals($before, $campaign);
        }
    }

    #[Test]
    public function aFingerprintOfAnEditedCampaignIsRefused(): void
    {
        [$pinned, $target, $campaign] = $this->scenario();
        $fingerprint = $campaign->planReleaseUpgrade($pinned, $target)->fingerprint();
        $campaign->editCharacter(CharacterId::fromString('c-1'), 'Vi', ['ally'], ['boss'], ['luck' => 7], $pinned);
        $before = clone $campaign;

        try {
            $campaign->upgradeRelease($pinned, $target, $fingerprint, $this->at);
            self::fail('The upgrade should be refused.');
        } catch (ReleaseUpgradeChanged) {
            self::assertEquals($before, $campaign);
        }
    }

    #[Test]
    public function aTargetThatIsNotNewerOrBelongsToAnotherGameSystemIsRefusedAndNothingChanges(): void
    {
        [$pinned, , $campaign] = $this->scenario();
        $before = clone $campaign;

        foreach ([[UpgradeFixtures::release(1), CampaignUpToDate::class], [UpgradeFixtures::release(2, key: 'noir'), UpgradeToAnotherGameSystem::class]] as [$target, $error]) {
            try {
                $campaign->upgradeRelease($pinned, $target, 'any', $this->at);
                self::fail('The upgrade should be refused.');
            } catch (\DomainException $refusal) {
                self::assertInstanceOf($error, $refusal);
                self::assertEquals($before, $campaign);
            }
        }
    }

    #[Test]
    public function aSnapshotThatIsNotThePinnedReleaseIsRefused(): void
    {
        [, $target, $campaign] = $this->scenario();

        $this->expectException(\LogicException::class);

        $campaign->upgradeRelease(UpgradeFixtures::release(5), $target, 'any', $this->at);
    }

    #[Test]
    public function pausedGuidanceRestartsPaused(): void
    {
        $pinned = UpgradeFixtures::release(1, flows: [UpgradeFixtures::flow('main', true)]);
        $target = UpgradeFixtures::release(2, flows: [UpgradeFixtures::flow('main', true)]);
        $campaign = UpgradeFixtures::campaign($pinned, UpgradeFixtures::flow('main', true));
        $campaign->pauseGuidance($this->at);

        $campaign->upgradeRelease($pinned, $target, $campaign->planReleaseUpgrade($pinned, $target)->fingerprint(), $this->at);

        $fresh = FlowRun::start();
        $fresh->pause($this->at);
        self::assertEquals($fresh, $campaign->flowRun());
        self::assertSame(FlowRunStatus::Paused, $campaign->flowRun()?->status());
    }

    #[Test]
    public function aReleaseWithoutFlowsMakesTheCampaignFreeAndFreePlayStaysFree(): void
    {
        $pinned = UpgradeFixtures::release(1, flows: [UpgradeFixtures::flow('main', true)]);
        $target = UpgradeFixtures::release(2);
        $guided = UpgradeFixtures::campaign($pinned, UpgradeFixtures::flow('main', true));
        $free = UpgradeFixtures::campaign($pinned);

        foreach ([$guided, $free] as $campaign) {
            $campaign->upgradeRelease($pinned, $target, $campaign->planReleaseUpgrade($pinned, $target)->fingerprint(), $this->at);

            self::assertNull($campaign->flowKey());
            self::assertNull($campaign->flowRun());
        }
    }

    #[Test]
    public function derivedValuesRecomputeFromTheUpgradedValues(): void
    {
        $pinned = UpgradeFixtures::release(1, [new NumberField('luck', 'Luck', max: 10)]);
        $target = UpgradeFixtures::release(2, [new NumberField('luck', 'Luck', max: 4), new DerivedField('dodge', 'Dodge', null, null, Formula::fromString('{luck} * 2'))]);
        $campaign = UpgradeFixtures::campaign($pinned);
        $campaign->addCharacter(CharacterId::fromString('c-1'), 'Vi', [], [], ['luck' => 9], $pinned);

        $campaign->upgradeRelease($pinned, $target, $campaign->planReleaseUpgrade($pinned, $target)->fingerprint(), $this->at);

        $character = $campaign->character(CharacterId::fromString('c-1'));
        self::assertInstanceOf(Character::class, $character);
        self::assertSame(['luck' => 4], $character->values());
        self::assertSame(['dodge' => 8], SheetValues::of($target->sheet(), $character->values(), [])->derived());
    }

    #[Test]
    public function aVersionOneCampaignUpgradesToVersionTwoAndEditsAgainstTheNewSheet(): void
    {
        $pinned = UpgradeFixtures::bare();
        $target = UpgradeFixtures::release(2, [new TextField('role', 'Role')], ['ally'], [Tracker::counter('heat', 'Heat', null, 0, 5, 1)], [], [UpgradeFixtures::flow('main', true)]);
        $campaign = UpgradeFixtures::campaign($pinned);
        $campaign->addCharacter(CharacterId::fromString('c-1'), 'Vi', [], [], [], $pinned);

        $campaign->upgradeRelease($pinned, $target, $campaign->planReleaseUpgrade($pinned, $target)->fingerprint(), $this->at);

        self::assertNull($campaign->flowKey());
        self::assertNull($campaign->flowRun());
        self::assertSame(['role' => ''], $campaign->character(CharacterId::fromString('c-1'))?->values());
        $edited = $campaign->editCharacter(CharacterId::fromString('c-1'), 'Vi', ['ally'], [], ['role' => 'Fixer'], $target);
        self::assertSame(['role' => 'Fixer'], $edited->values());
    }

    #[Test]
    public function aGuidedSceneOpenAtUpgradeTimeGoesOnAsAFreeSceneAndTheRestartedFlowRunIsNotInIt(): void
    {
        $legwork = new SceneType('legwork', 'Legwork', 'A purpose.', null, [], new StepList(), new StepList(), new StepList());
        $flow = UpgradeFixtures::flow('main', true);
        $pinned = UpgradeFixtures::release(1, flows: [$flow], sceneTypes: [$legwork]);
        $target = UpgradeFixtures::release(2, flows: [$flow], sceneTypes: [$legwork]);
        $campaign = UpgradeFixtures::campaign($pinned, $flow);
        $campaign->startSession($this->at, $pinned);
        $guided = $campaign->currentScene();
        self::assertInstanceOf(Scene::class, $guided);
        self::assertNotNull($campaign->flowRun()?->scene(), 'The scene is guided before the upgrade.');

        $campaign->upgradeRelease($pinned, $target, $campaign->planReleaseUpgrade($pinned, $target)->fingerprint(), $this->at);

        self::assertEquals($guided, $campaign->currentScene(), 'The scene stays as it was.');
        self::assertNull($campaign->flowRun()?->scene(), 'The restarted FlowRun is in no scene.');
        self::assertSame(FlowRunStage::ScenePick, $campaign->flowRun()->stage());
        $campaign->pickSceneType('legwork', $target, $this->at);
        self::assertSame([1, 2], array_map(static fn (Scene $scene): int => $scene->number(), $campaign->currentSession()?->scenes() ?? []), 'Guidance goes on with a new scene; the old one is not guided any more.');
        self::assertSame($campaign->currentScene()?->number(), $campaign->flowRun()->scene()[1] ?? null);
    }

    /**
     * A campaign on release 1 with a Character, an NPC, a Thread, a Tracker value, slot facts and
     * guidance, and release 2 that changes something of each.
     *
     * @return array{GameSystemSnapshot, GameSystemSnapshot, Campaign}
     */
    private function scenario(): array
    {
        $heat = Tracker::counter('heat', 'Heat', null, 0, 10, 2);
        $slots = [new FactSlot('city', 'City', FactSlotType::Text), new FactSlot('prince', 'The Prince', FactSlotType::Npc), new FactSlot('goal', 'Goal', FactSlotType::Text)];
        $pinned = UpgradeFixtures::release(
            1,
            [new NumberField('luck', 'Luck', max: 10), new TrackField('hp', 'Hit points', max: 40), new ListField('gear', 'Gear', maxItems: 3), new TextField('role', 'Role')],
            ['ally', 'rival', 'boss'],
            [$heat, Tracker::counter('cash', 'Cash', null, 0, 10, 5)],
            $slots,
            [UpgradeFixtures::flow('main', true)],
        );
        $target = UpgradeFixtures::release(
            2,
            [new NumberField('luck', 'Luck', max: 3), new TrackField('hp', 'Hit points', max: 10), new ListField('gear', 'Gear', maxItems: 2), new NumberField('nerve', 'Nerve', default: 5), new TextField('role', 'Role', maxLength: 0)],
            ['ally'],
            [Tracker::counter('heat', 'Heat', null, 0, 6, 2)],
            [$slots[2]],
            [UpgradeFixtures::flow('main', true)],
        );
        $campaign = UpgradeFixtures::campaign($pinned, UpgradeFixtures::flow('main', true), [$heat]);
        $campaign->setTrackerValue($heat, 9);
        $campaign->addCharacter(CharacterId::fromString('c-1'), 'Vi', ['boss', 'ally'], ['boss'], ['luck' => 8, 'hp' => ['current' => 40, 'max' => 40], 'gear' => ['a', 'b', 'c'], 'role' => 'Fixer'], $pinned);
        $campaign->addNpc(NpcId::fromString('npc-1'), 'Mira', null, ['rival', 'ally'], ['rival'], 1, $pinned);
        $campaign->addThread(ThreadId::fromString('thread-1'), 'Vault', ['boss'], ['boss'], 1, $pinned);
        $campaign->fillFactSlot(FactId::fromString('fact-1'), 'city', FactFill::text('Night City'), $pinned);
        $campaign->fillFactSlot(FactId::fromString('fact-2'), 'prince', FactFill::newNpc(NpcId::fromString('npc-2'), 'Mithras'), $pinned);
        $campaign->fillFactSlot(FactId::fromString('fact-3'), 'goal', FactFill::text('Rob the vault'), $pinned);

        return [$pinned, $target, $campaign];
    }
}
