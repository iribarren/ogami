<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignNotFound;
use App\Play\Application\OwnedCampaigns;
use App\Play\Application\TransferCampaign;
use App\Play\Application\TransferCampaignHandler;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignAlreadyExists;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\Characters\Character;
use App\Play\Domain\Campaign\Characters\CharacterAlreadyExists;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\InvalidCharacter;
use App\Play\Domain\Campaign\Characters\UnknownCharacter;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\InvalidCampaignName;
use App\Play\Domain\Campaign\Lists\ItemStatus;
use App\Play\Domain\Campaign\Lists\Npc;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\Campaign\Transfer\TransferFromOutdatedRelease;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\UnknownFlow;
use App\Tests\Support\Play\FixedClock;
use App\Tests\Support\Play\InMemoryCampaignRepository;
use App\Tests\Support\Play\InMemoryPublishedGameSystemReleases;
use App\Tests\Support\Play\Snapshots;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TransferCampaign::class)]
#[CoversClass(TransferCampaignHandler::class)]
final class TransferCampaignHandlerTest extends TestCase
{
    private InMemoryCampaignRepository $campaigns;
    private InMemoryPublishedGameSystemReleases $releases;
    private TransferCampaignHandler $handler;

    protected function setUp(): void
    {
        $this->campaigns = new InMemoryCampaignRepository();
        $this->releases = new InMemoryPublishedGameSystemReleases();
        $this->releases->add(Snapshots::withFlows('heist', 'Heist', 1));
        $this->releases->add(Snapshots::withFlows('heist', 'Heist', 2));
        $this->handler = new TransferCampaignHandler(new OwnedCampaigns($this->campaigns), $this->campaigns, $this->releases, new FixedClock('2026-10-30T10:00:00+00:00'));
        $this->campaigns->add($this->source('campaign-1', 2));
    }

    #[Test]
    public function itAddsTheNewCampaignOfTheOwnerOnTheLatestReleaseWithTheChosenCharactersAndFlow(): void
    {
        ($this->handler)(new TransferCampaign('campaign-1', 'user-1', 'campaign-2', '  Making a Name  ', 'one-shot', ['c-1']));

        $new = $this->campaigns->ofId(CampaignId::fromString('campaign-2'));
        self::assertNotNull($new);
        self::assertTrue($new->isOwnedBy('user-1'));
        self::assertSame(['Making a Name', 'heist', 2, 'one-shot'], [$new->name(), $new->pinnedRelease()->gameSystemKey(), $new->pinnedRelease()->releaseVersion(), $new->flowKey()]);
        self::assertEquals(new \DateTimeImmutable('2026-10-30T10:00:00+00:00'), $new->createdAt());
        self::assertSame(['c-1'], array_map(static fn (Character $character): string => $character->id()->toString(), $new->characters()));
        self::assertSame(['npc-1'], array_map(static fn (Npc $npc): string => $npc->id()->toString(), $new->npcs()));
        self::assertNull($new->npc(NpcId::fromString('npc-1'))?->promotedTo());
    }

    #[Test]
    public function withoutAFlowKeyTheNewCampaignPlaysFreelyWhateverTheReleaseDefault(): void
    {
        ($this->handler)(new TransferCampaign('campaign-1', 'user-1', 'campaign-2', 'Making a Name', null, []));

        self::assertNull($this->campaigns->ofId(CampaignId::fromString('campaign-2'))?->flowKey());
    }

    #[Test]
    public function theSourceIsLeftAsItWas(): void
    {
        ($this->handler)(new TransferCampaign('campaign-1', 'user-1', 'campaign-2', 'Making a Name', 'one-shot', ['c-1']));

        $source = $this->campaigns->ofId(CampaignId::fromString('campaign-1'));
        self::assertSame(1, $source?->version());
        self::assertSame(['c-1', 'c-2'], array_map(static fn (Character $character): string => $character->id()->toString(), $source->characters()));
        self::assertSame('c-2', $source->npc(NpcId::fromString('npc-1'))?->promotedTo()?->toString());
        self::assertSame('the-heist', $source->flowKey());
    }

    #[Test]
    public function anUnknownOrForeignSourceIsNotFound(): void
    {
        foreach ([['ghost', 'user-1'], ['campaign-1', 'user-2'], ['', 'user-1']] as [$campaignId, $userId]) {
            try {
                ($this->handler)(new TransferCampaign($campaignId, $userId, 'campaign-2', 'Making a Name', null, []));
                self::fail('The source was found.');
            } catch (CampaignNotFound) {
                self::assertNull($this->campaigns->ofId(CampaignId::fromString('campaign-2')));
            }
        }
    }

    #[Test]
    public function aSourceOnAnOlderReleaseIsRefusedAndNothingIsAdded(): void
    {
        $this->campaigns->add($this->source('campaign-old', 1));

        $this->expectException(TransferFromOutdatedRelease::class);

        try {
            ($this->handler)(new TransferCampaign('campaign-old', 'user-1', 'campaign-2', 'Making a Name', null, []));
        } finally {
            self::assertNull($this->campaigns->ofId(CampaignId::fromString('campaign-2')));
        }
    }

    #[Test]
    public function refusedInputAddsNothing(): void
    {
        foreach ([
            ['Making a Name', 'the-long-con', [], UnknownFlow::class],
            ['   ', null, [], InvalidCampaignName::class],
            ['Making a Name', null, ['ghost'], UnknownCharacter::class],
            ['Making a Name', null, ['c-1', 'c-1'], CharacterAlreadyExists::class],
            ['Making a Name', null, [' '], InvalidCharacter::class],
        ] as [$name, $flowKey, $characterIds, $error]) {
            try {
                ($this->handler)(new TransferCampaign('campaign-1', 'user-1', 'campaign-2', $name, $flowKey, $characterIds));
                self::fail('The input was accepted.');
            } catch (\Throwable $exception) {
                self::assertInstanceOf($error, $exception);
            }

            self::assertNull($this->campaigns->ofId(CampaignId::fromString('campaign-2')));
        }
    }

    #[Test]
    public function anIdAlreadyTakenIsRejectedAndTheExistingCampaignIsKept(): void
    {
        try {
            ($this->handler)(new TransferCampaign('campaign-1', 'user-1', 'campaign-1', 'Making a Name', null, []));
            self::fail('A campaign id already taken was accepted.');
        } catch (CampaignAlreadyExists) {
            self::assertSame('The job', $this->campaigns->ofId(CampaignId::fromString('campaign-1'))?->name());
        }
    }

    #[Test]
    public function aGameSystemWithoutAReleaseIsNotFound(): void
    {
        $this->campaigns->add(Campaign::create(CampaignId::fromString('campaign-3'), 'user-1', 'Lost', PinnedRelease::of('gone', 1, 'Gone'), new \DateTimeImmutable()));

        $this->expectException(GameSystemReleaseNotFound::class);

        ($this->handler)(new TransferCampaign('campaign-3', 'user-1', 'campaign-2', 'Making a Name', null, []));
    }

    private function source(string $id, int $version): Campaign
    {
        $npc = Npc::of(NpcId::fromString('npc-1'), 'Mira', null, [], [], 1, ItemStatus::Closed, CharacterId::fromString('c-2'));
        $characters = [
            Character::of(CharacterId::fromString('c-1'), 'Vi', [], [], []),
            Character::of(CharacterId::fromString('c-2'), 'Mira', [], [], [], NpcId::fromString('npc-1')),
        ];

        return Campaign::reconstitute(CampaignId::fromString($id), 'user-1', 'The job', PinnedRelease::of('heist', $version, 'Heist'), new \DateTimeImmutable('2026-10-01T09:00:00+00:00'), [], [], 'the-heist', FlowRun::start(), [$npc], [], [], $characters);
    }
}
