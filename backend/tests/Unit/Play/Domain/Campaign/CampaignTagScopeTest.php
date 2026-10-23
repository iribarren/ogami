<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Lists\ItemRules;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\TagNotApplicable;
use App\Play\Domain\Campaign\Lists\ThreadId;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\Tag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A Tag limited to some entity kinds (ADR 0018, addendum of 2026-10-24) is refused on the others,
 * and a command that refuses it changes nothing. The release has "villain"
 * (NPCs), "goal" (Threads), "hero" (Characters) and "ally" (every kind).
 */
#[CoversClass(Campaign::class)]
#[CoversClass(ItemRules::class)]
#[CoversClass(TagNotApplicable::class)]
#[CoversClass(Tag::class)]
final class CampaignTagScopeTest extends TestCase
{
    private GameSystemSnapshot $release;

    protected function setUp(): void
    {
        $this->release = new GameSystemSnapshot('heist', 'Heist', 1, null, [], [], tags: [
            new Tag('villain', 'Villain', null, [ItemKind::Npc]),
            new Tag('goal', 'Goal', null, [ItemKind::Thread]),
            new Tag('hero', 'Hero', null, [ItemKind::Character]),
            new Tag('ally', 'Ally'),
        ]);
    }

    #[Test]
    public function aTagAppliesToTheKindsItIsScopedToAndAnUnscopedOneToAll(): void
    {
        $campaign = $this->campaign();

        $campaign->addNpc(NpcId::fromString('npc-1'), 'Mira', null, ['villain', 'ally'], [], 1, $this->release);
        $campaign->addThread(ThreadId::fromString('thread-1'), 'Vault', ['goal', 'ally'], [], 1, $this->release);
        $campaign->addCharacter(CharacterId::fromString('c-1'), 'Vi', ['hero', 'ally'], [], [], $this->release);

        self::assertSame([['villain', 'ally'], ['goal', 'ally'], ['hero', 'ally']], [$campaign->npcs()[0]->tags(), $campaign->threads()[0]->tags(), $campaign->characters()[0]->tags()]);
    }

    #[Test]
    public function aTagOutsideItsScopeIsRefusedOnEveryCommandAndNothingChanges(): void
    {
        $campaign = $this->campaign();
        $campaign->addNpc(NpcId::fromString('npc-1'), 'Mira', null, ['villain'], [], 1, $this->release);
        $campaign->addThread(ThreadId::fromString('thread-1'), 'Vault', ['goal'], [], 1, $this->release);
        $campaign->addCharacter(CharacterId::fromString('c-1'), 'Vi', ['hero'], [], [], $this->release);
        $before = [$campaign->npcs(), $campaign->threads(), $campaign->characters()];

        $refused = [
            'an NPC added with a Thread tag' => ['Tag "goal" does not apply to NPCs.', static fn (Campaign $c, GameSystemSnapshot $r): mixed => $c->addNpc(NpcId::fromString('npc-2'), 'Brann', null, ['goal'], [], 1, $r)],
            'an NPC edited with a Character tag' => ['Tag "hero" does not apply to NPCs.', static fn (Campaign $c, GameSystemSnapshot $r): mixed => $c->editNpc(NpcId::fromString('npc-1'), 'Mira', null, ['hero'], [], 1, $r)],
            'a Thread added with an NPC tag' => ['Tag "villain" does not apply to Threads.', static fn (Campaign $c, GameSystemSnapshot $r): mixed => $c->addThread(ThreadId::fromString('thread-2'), 'Heist', ['villain'], [], 1, $r)],
            'a Thread edited with a Character tag' => ['Tag "hero" does not apply to Threads.', static fn (Campaign $c, GameSystemSnapshot $r): mixed => $c->editThread(ThreadId::fromString('thread-1'), 'Vault', ['hero'], [], 1, $r)],
            'a Character added with an NPC tag' => ['Tag "villain" does not apply to Characters.', static fn (Campaign $c, GameSystemSnapshot $r): mixed => $c->addCharacter(CharacterId::fromString('c-2'), 'Jo', ['villain'], [], [], $r)],
            'a Character edited with a Thread tag' => ['Tag "goal" does not apply to Characters.', static fn (Campaign $c, GameSystemSnapshot $r): mixed => $c->editCharacter(CharacterId::fromString('c-1'), 'Vi', ['goal'], [], [], $r)],
            'an NPC created by an effect with a Thread tag' => ['Tag "goal" does not apply to NPCs.', static fn (Campaign $c, GameSystemSnapshot $r): mixed => $c->createNpcByEffect('Rival', null, ['goal'], $r)],
            'a Thread created by an effect with an NPC tag' => ['Tag "villain" does not apply to Threads.', static fn (Campaign $c, GameSystemSnapshot $r): mixed => $c->createThreadByEffect('Heist', ['villain'], $r)],
        ];
        foreach ($refused as $name => [$message, $command]) {
            try {
                $command($campaign, $this->release);
                self::fail($name.' was accepted.');
            } catch (TagNotApplicable $refusal) {
                self::assertSame($message, $refusal->getMessage(), $name);
            }
        }

        self::assertEquals($before, [$campaign->npcs(), $campaign->threads(), $campaign->characters()]);
    }

    #[Test]
    public function promotingAnNpcKeepsOnlyTheTagsThatApplyToCharacters(): void
    {
        $campaign = $this->campaign();
        $campaign->addNpc(NpcId::fromString('npc-1'), 'Mira', null, ['villain', 'ally'], [], 1, $this->release);

        $character = $campaign->promoteNpc(NpcId::fromString('npc-1'), CharacterId::fromString('c-1'), $this->release);

        self::assertSame(['ally'], $character->tags());
        self::assertSame(['villain', 'ally'], $campaign->npcs()[0]->tags(), 'The NPC keeps its tags.');
    }

    private function campaign(): Campaign
    {
        return Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-10T09:00:00+00:00'));
    }
}
