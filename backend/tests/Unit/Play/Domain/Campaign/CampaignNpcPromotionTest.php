<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\CampaignLimitReached;
use App\Play\Domain\Campaign\Characters\Character;
use App\Play\Domain\Campaign\Characters\CharacterAlreadyExists;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Lists\InvalidNpc;
use App\Play\Domain\Campaign\Lists\InvalidPromotion;
use App\Play\Domain\Campaign\Lists\ItemStatus;
use App\Play\Domain\Campaign\Lists\Npc;
use App\Play\Domain\Campaign\Lists\NpcAlreadyPromoted;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\UnknownNpc;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Play\Domain\GameSystem\Sheet\TextField;
use App\Play\Domain\GameSystem\Tag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Promoting an NPC makes a Character of it and closes the NPC for good: all or nothing, once, and
 * the two sides of a promotion always name each other, also when a stored campaign is read back.
 */
#[CoversClass(Campaign::class)]
#[CoversClass(Npc::class)]
#[CoversClass(NpcAlreadyPromoted::class)]
#[CoversClass(InvalidPromotion::class)]
final class CampaignNpcPromotionTest extends TestCase
{
    private GameSystemSnapshot $release;

    protected function setUp(): void
    {
        $this->release = new GameSystemSnapshot('heist', 'Heist', 1, null, [], [], tags: [new Tag('ally', 'Ally')], sheet: new SheetTemplate([new TextField('role', 'Role'), new NumberField('reflexes', 'Reflexes', min: 1, max: 10, default: 4)]));
    }

    #[Test]
    public function anOpenNpcBecomesACharacterWithItsNameTagsAndTheDefaultValues(): void
    {
        $campaign = $this->campaignWithNpc();

        $character = $campaign->promoteNpc(NpcId::fromString('npc-1'), CharacterId::fromString('c-1'), $this->release);

        self::assertSame(['Mira', ['ally'], ['Fence'], ['role' => '', 'reflexes' => 4], 'npc-1'], [$character->name(), $character->tags(), $character->freeTags(), $character->values(), $character->promotedFrom()?->toString()]);
        self::assertSame([$character], $campaign->characters());
        $npc = $campaign->npc(NpcId::fromString('npc-1'));
        self::assertInstanceOf(Npc::class, $npc);
        self::assertSame([ItemStatus::Closed, true, 'c-1', 'Wants the ledger', 3], [$npc->status(), $npc->isPromoted(), $npc->promotedTo()?->toString(), $npc->agenda(), $npc->weight()]);
    }

    #[Test]
    public function aClosedNpcCanBePromoted(): void
    {
        $campaign = $this->campaignWithNpc();
        $campaign->closeNpc(NpcId::fromString('npc-1'));

        $campaign->promoteNpc(NpcId::fromString('npc-1'), CharacterId::fromString('c-1'), $this->release);

        self::assertSame('c-1', $campaign->npc(NpcId::fromString('npc-1'))?->promotedTo()?->toString());
    }

    #[Test]
    public function aPromotedNpcIsNeitherPromotedAgainNorReopened(): void
    {
        $campaign = $this->campaignWithNpc();
        $campaign->promoteNpc(NpcId::fromString('npc-1'), CharacterId::fromString('c-1'), $this->release);
        $before = [$campaign->npcs(), $campaign->characters()];

        foreach ([
            fn (): Character => $campaign->promoteNpc(NpcId::fromString('npc-1'), CharacterId::fromString('c-2'), $this->release),
            static fn () => $campaign->reopenNpc(NpcId::fromString('npc-1')),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('The attempt was accepted.');
            } catch (NpcAlreadyPromoted $exception) {
                self::assertStringContainsString('NPC "npc-1"', $exception->getMessage());
            }

            self::assertSame($before, [$campaign->npcs(), $campaign->characters()]);
        }
    }

    #[Test]
    public function closingAndEditingAPromotedNpcKeepsItClosedAndPromoted(): void
    {
        $campaign = $this->campaignWithNpc();
        $campaign->promoteNpc(NpcId::fromString('npc-1'), CharacterId::fromString('c-1'), $this->release);

        $campaign->closeNpc(NpcId::fromString('npc-1'));
        $edited = $campaign->editNpc(NpcId::fromString('npc-1'), 'Mira Vane', null, [], [], 1, $this->release);

        self::assertSame(['Mira Vane', ItemStatus::Closed, 'c-1'], [$edited->name(), $edited->status(), $edited->promotedTo()?->toString()]);
        self::assertSame([$edited], $campaign->npcs());
    }

    #[Test]
    public function aFailedPromotionChangesNothing(): void
    {
        $campaign = $this->campaignWithNpc();
        $campaign->addCharacter(CharacterId::fromString('c-1'), 'Vi', [], [], [], $this->release);
        $before = [$campaign->npcs(), $campaign->characters()];

        foreach ([['ghost', 'c-2', UnknownNpc::class], ['npc-1', 'c-1', CharacterAlreadyExists::class]] as [$npcId, $characterId, $error]) {
            try {
                $campaign->promoteNpc(NpcId::fromString($npcId), CharacterId::fromString($characterId), $this->release);
                self::fail('The promotion was accepted.');
            } catch (\DomainException $exception) {
                self::assertInstanceOf($error, $exception);
            }

            self::assertSame($before, [$campaign->npcs(), $campaign->characters()]);
        }
    }

    #[Test]
    public function aCampaignAtTheCharacterLimitRefusesAPromotionAndChangesNothing(): void
    {
        $campaign = $this->campaignWithNpc();
        for ($i = 1; $i <= Campaign::MAX_CHARACTERS; ++$i) {
            $campaign->addCharacter(CharacterId::fromString('c-'.$i), 'Character '.$i, [], [], [], $this->release);
        }

        try {
            $campaign->promoteNpc(NpcId::fromString('npc-1'), CharacterId::fromString('c-new'), $this->release);
            self::fail('The promotion was accepted.');
        } catch (CampaignLimitReached) {
        }

        self::assertFalse($campaign->npc(NpcId::fromString('npc-1'))?->isPromoted());
        self::assertCount(Campaign::MAX_CHARACTERS, $campaign->characters());
    }

    #[Test]
    public function aPromotedNpcThatIsOpenIsRefused(): void
    {
        $this->expectException(InvalidNpc::class);

        Npc::of(NpcId::fromString('npc-1'), 'Mira', null, [], [], promotedTo: CharacterId::fromString('c-1'));
    }

    #[Test]
    public function aStoredPromotionNamedByBothSidesIsRead(): void
    {
        $campaign = $this->reconstituted(
            [Npc::of(NpcId::fromString('npc-1'), 'Mira', null, [], [], status: ItemStatus::Closed, promotedTo: CharacterId::fromString('c-1'))],
            [Character::of(CharacterId::fromString('c-1'), 'Mira', [], [], [], NpcId::fromString('npc-1'))],
        );

        self::assertSame('c-1', $campaign->npc(NpcId::fromString('npc-1'))?->promotedTo()?->toString());
    }

    #[Test]
    public function aStoredPromotionNamedByOneSideOnlyIsRefused(): void
    {
        $promotedNpc = Npc::of(NpcId::fromString('npc-1'), 'Mira', null, [], [], status: ItemStatus::Closed, promotedTo: CharacterId::fromString('c-1'));
        $closedNpc = Npc::of(NpcId::fromString('npc-1'), 'Mira', null, [], [], status: ItemStatus::Closed);
        $fromNpc = Character::of(CharacterId::fromString('c-1'), 'Mira', [], [], [], NpcId::fromString('npc-1'));
        $otherCharacter = Character::of(CharacterId::fromString('c-2'), 'Vi', [], [], [], NpcId::fromString('npc-1'));
        $plain = Character::of(CharacterId::fromString('c-1'), 'Mira', [], [], []);

        foreach ([
            'an NPC promoted to a missing Character' => [[$promotedNpc], []],
            'an NPC promoted to a Character from nowhere' => [[$promotedNpc], [$plain]],
            'an NPC promoted to a Character from another NPC' => [[$promotedNpc, Npc::of(NpcId::fromString('npc-2'), 'Brann', null, [], [])], [Character::of(CharacterId::fromString('c-1'), 'Mira', [], [], [], NpcId::fromString('npc-2'))]],
            'a Character from a missing NPC' => [[], [$fromNpc]],
            'a Character from an NPC that was not promoted' => [[$closedNpc], [$fromNpc]],
            'a Character from an NPC promoted to another Character' => [[$promotedNpc], [$fromNpc, $otherCharacter]],
        ] as $case => [$npcs, $characters]) {
            try {
                $this->reconstituted($npcs, $characters);
                self::fail($case.' was read.');
            } catch (InvalidPromotion $exception) {
                self::assertNotSame('', $exception->getMessage(), $case);
            }
        }
    }

    private function campaignWithNpc(): Campaign
    {
        $campaign = Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-12T09:00:00+00:00'));
        $campaign->addNpc(NpcId::fromString('npc-1'), 'Mira', 'Wants the ledger', ['ally'], ['Fence'], 3, $this->release);

        return $campaign;
    }

    /**
     * @param list<Npc>       $npcs
     * @param list<Character> $characters
     */
    private function reconstituted(array $npcs, array $characters): Campaign
    {
        return Campaign::reconstitute(CampaignId::fromString('campaign-1'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-12T09:00:00+00:00'), [], npcs: $npcs, characters: $characters);
    }
}
