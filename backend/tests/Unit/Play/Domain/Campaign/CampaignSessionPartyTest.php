<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\UnknownCharacter;
use App\Play\Domain\Campaign\Hook;
use App\Play\Domain\Campaign\InvalidSceneCast;
use App\Play\Domain\Campaign\InvalidSessionParty;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\NoCurrentSession;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\Campaign\Scene;
use App\Play\Domain\Campaign\Session;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A Session's party (the Characters playing, in order, without repeats) and the Characters part of
 * a Scene's cast: a new Scene and the next Session start from the party, a new or promoted
 * Character joins them, and every edit is all or nothing.
 */
#[CoversClass(Campaign::class)]
#[CoversClass(Session::class)]
#[CoversClass(Scene::class)]
#[CoversClass(InvalidSessionParty::class)]
#[CoversClass(InvalidSceneCast::class)]
final class CampaignSessionPartyTest extends TestCase
{
    private GameSystemSnapshot $release;

    protected function setUp(): void
    {
        $this->release = new GameSystemSnapshot('heist', 'Heist', 1, null, [], []);
    }

    #[Test]
    public function theFirstSessionStartsWithAnEmptyPartyAndAnEndedSessionKeepsItsParty(): void
    {
        $campaign = $this->campaign();
        $campaign->startSession(new \DateTimeImmutable());
        self::assertSame([], $campaign->currentSession()?->party());

        $campaign->editCurrentSessionParty([$this->id('vi'), $this->id('jax')]);
        $ended = $campaign->endSession(new \DateTimeImmutable());

        self::assertSame(['vi', 'jax'], $this->ids($ended->party()));
    }

    #[Test]
    public function theNextSessionStartsWithThePartyOfThePreviousOne(): void
    {
        $campaign = $this->campaign();
        $campaign->startSession(new \DateTimeImmutable());
        $campaign->editCurrentSessionParty([$this->id('jax'), $this->id('vi')]);
        $campaign->endSession(new \DateTimeImmutable());

        $second = $campaign->startSession(new \DateTimeImmutable());
        $campaign->editCurrentSessionParty([$this->id('vi')]);

        self::assertSame(['jax', 'vi'], $this->ids($second->party()));
        self::assertSame([['jax', 'vi'], ['vi']], array_map(fn (Session $session): array => $this->ids($session->party()), $campaign->sessions()));
    }

    #[Test]
    public function aNewSceneOfEitherKindStartsWithThePartyAsItsCharactersAndNoNpcs(): void
    {
        $campaign = $this->campaign();
        $campaign->startSession(new \DateTimeImmutable());
        $campaign->editCurrentSessionParty([$this->id('vi'), $this->id('jax')]);

        $scene = $campaign->startScene('At the gate', new \DateTimeImmutable());
        $hook = $campaign->startHookScene(Hook::WorldTurn, 'The world moves', new \DateTimeImmutable());

        self::assertSame([['vi', 'jax'], ['vi', 'jax']], [$this->ids($scene->characters()), $this->ids($hook->characters())]);
        self::assertSame([[], []], [$scene->cast(), $hook->cast()]);
        self::assertSame([], Scene::start(1, 'Gate', new \DateTimeImmutable())->characters());
    }

    #[Test]
    public function editingThePartyKeepsTheCharactersOfTheScenes(): void
    {
        $campaign = $this->campaign();
        $campaign->startSession(new \DateTimeImmutable());
        $campaign->editCurrentSessionParty([$this->id('vi')]);
        $campaign->startScene('At the gate', new \DateTimeImmutable());

        $campaign->editCurrentSessionParty([$this->id('jax')]);

        self::assertSame(['vi'], $this->sceneCharacters($campaign));
    }

    #[Test]
    public function aPartyNeedsASessionUnderWayKnownCharactersAndNoRepeats(): void
    {
        $campaign = $this->campaign();
        try {
            $campaign->editCurrentSessionParty([]);
            self::fail('There was no session under way.');
        } catch (NoCurrentSession) {
        }

        $campaign->startSession(new \DateTimeImmutable());
        $campaign->editCurrentSessionParty([$this->id('vi')]);
        foreach ([[$this->id('jax'), $this->id('ghost')], [$this->id('jax'), $this->id('vi'), $this->id('jax')]] as $party) {
            try {
                $campaign->editCurrentSessionParty($party);
                self::fail('The party was accepted.');
            } catch (UnknownCharacter|InvalidSessionParty) {
            }

            self::assertSame(['vi'], $this->ids($campaign->currentSession()?->party() ?? []));
        }

        $campaign->endSession(new \DateTimeImmutable());
        $this->expectException(NoCurrentSession::class);

        $campaign->editCurrentSessionParty([]);
    }

    #[Test]
    public function theCastEditReplacesTheCharactersOnlyWhenGivenAndIsAllOrNothing(): void
    {
        $campaign = $this->campaign();
        $campaign->addNpc(NpcId::fromString('ana'), 'Ana', null, [], [], 1, $this->release);
        $campaign->startSession(new \DateTimeImmutable());
        $campaign->editCurrentSessionParty([$this->id('vi')]);
        $campaign->startScene('At the gate', new \DateTimeImmutable());

        $campaign->editCurrentSceneCast([NpcId::fromString('ana')]);
        self::assertSame(['vi'], $this->sceneCharacters($campaign));

        $campaign->editCurrentSceneCast([], [$this->id('jax'), $this->id('vi')]);
        self::assertSame([[], ['jax', 'vi']], [$this->sceneCast($campaign), $this->sceneCharacters($campaign)]);

        $campaign->editCurrentSceneCast([], []);
        self::assertSame([], $campaign->currentScene()?->characters());

        $campaign->editCurrentSceneCast([NpcId::fromString('ana')], [$this->id('vi')]);
        foreach ([[$this->id('ghost')], [$this->id('vi'), $this->id('vi')]] as $characters) {
            try {
                $campaign->editCurrentSceneCast([], $characters);
                self::fail('The cast was accepted.');
            } catch (UnknownCharacter|InvalidSceneCast) {
            }

            self::assertSame(['ana'], $this->sceneCast($campaign));
            self::assertSame(['vi'], $this->sceneCharacters($campaign));
        }
    }

    #[Test]
    public function aNewCharacterJoinsThePartyAndTheCurrentSceneWhileASessionIsUnderWay(): void
    {
        $campaign = $this->campaign();
        $campaign->addCharacter($this->id('early'), 'Early', [], [], [], $this->release);
        self::assertSame([], $campaign->sessions());

        $campaign->startSession(new \DateTimeImmutable());
        $campaign->addCharacter($this->id('before-scene'), 'Before', [], [], [], $this->release);
        self::assertSame(['before-scene'], $this->ids($campaign->currentSession()?->party() ?? []));

        $campaign->startScene('At the gate', new \DateTimeImmutable());
        $campaign->startScene('Inside', new \DateTimeImmutable());
        $campaign->addCharacter($this->id('late'), 'Late', [], [], [], $this->release);

        $session = $campaign->currentSession();
        self::assertSame(['before-scene', 'late'], $this->ids($session?->party() ?? []));
        self::assertSame([['before-scene'], ['before-scene', 'late']], array_map(fn (Scene $scene): array => $this->ids($scene->characters()), $session?->scenes() ?? []));

        $campaign->endSession(new \DateTimeImmutable());
        $campaign->addCharacter($this->id('after'), 'After', [], [], [], $this->release);

        self::assertSame(['before-scene', 'late'], $this->firstSessionParty($campaign));
    }

    #[Test]
    public function aFailedCreationLeavesThePartyAndTheCastAsTheyWere(): void
    {
        $campaign = $this->campaign();
        $campaign->startSession(new \DateTimeImmutable());
        $campaign->startScene('At the gate', new \DateTimeImmutable());
        $campaign->addCharacter($this->id('kit'), 'Kit', [], [], [], $this->release);
        $before = clone $campaign;

        try {
            $campaign->addCharacter($this->id('kit'), 'Kit again', [], [], [], $this->release);
            self::fail('The id was taken.');
        } catch (\DomainException) {
        }

        self::assertEquals($before->sessions(), $campaign->sessions());
    }

    #[Test]
    public function promotingAnNpcSwapsItForItsCharacterInTheCurrentSceneOnly(): void
    {
        $campaign = $this->campaign();
        $campaign->addNpc(NpcId::fromString('mira'), 'Mira', null, [], [], 1, $this->release);
        $campaign->addNpc(NpcId::fromString('ana'), 'Ana', null, [], [], 1, $this->release);
        $campaign->startSession(new \DateTimeImmutable());
        $campaign->startScene('Earlier', new \DateTimeImmutable());
        $campaign->editCurrentSceneCast([NpcId::fromString('mira')]);
        $campaign->startScene('At the gate', new \DateTimeImmutable());
        $campaign->editCurrentSceneCast([NpcId::fromString('ana'), NpcId::fromString('mira')], [$this->id('vi')]);

        $campaign->promoteNpc(NpcId::fromString('mira'), $this->id('mira-c'), $this->release);

        $session = $campaign->currentSession();
        self::assertSame(['mira-c'], $this->ids($session?->party() ?? []));
        $current = $session?->scenes()[1];
        self::assertSame([['ana'], ['vi', 'mira-c']], [array_map(static fn (NpcId $id): string => $id->toString(), $current?->cast() ?? []), $this->ids($current?->characters() ?? [])]);
        $earlier = $session?->scenes()[0];
        self::assertSame([['mira'], []], [array_map(static fn (NpcId $id): string => $id->toString(), $earlier?->cast() ?? []), $this->ids($earlier?->characters() ?? [])]);
    }

    #[Test]
    public function promotingAnNpcOutsideTheCurrentSceneCastOnlyAddsTheCharacter(): void
    {
        $campaign = $this->campaign();
        $campaign->addNpc(NpcId::fromString('mira'), 'Mira', null, [], [], 1, $this->release);
        $campaign->promoteNpc(NpcId::fromString('mira'), $this->id('mira-c'), $this->release);
        self::assertSame([], $campaign->sessions());

        $campaign->startSession(new \DateTimeImmutable());
        $campaign->addNpc(NpcId::fromString('ana'), 'Ana', null, [], [], 1, $this->release);
        $campaign->startScene('At the gate', new \DateTimeImmutable());
        $campaign->editCurrentSceneCast([NpcId::fromString('ana')]);
        $campaign->promoteNpc(NpcId::fromString('ana'), $this->id('ana-c'), $this->release);

        self::assertSame([[], ['ana-c']], [$this->sceneCast($campaign), $this->sceneCharacters($campaign)]);
    }

    #[Test]
    public function aReconstitutedCampaignRefusesAPartyOrSceneNamingAMissingCharacter(): void
    {
        $at = new \DateTimeImmutable();
        $campaign = static fn (Session $session): Campaign => Campaign::reconstitute(CampaignId::fromString('campaign-1'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), $at, [$session]);

        try {
            $campaign(Session::reconstitute(1, $at, [], null, [$this->id('ghost')]));
            self::fail('The party was accepted.');
        } catch (InvalidSessionParty $exception) {
            self::assertSame('The party of session 1 names Character "ghost", which the campaign does not hold.', $exception->getMessage());
        }

        $this->expectExceptionObject(InvalidSceneCast::unknownCharacter(1, 1, $this->id('ghost')));

        $campaign(Session::reconstitute(1, $at, [Scene::reconstitute(1, 'Gate', $at, characters: [$this->id('ghost')])]));
    }

    #[Test]
    public function aPartyOrSceneListingACharacterTwiceCannotBeBuilt(): void
    {
        $twice = [$this->id('vi'), $this->id('vi')];
        foreach ([
            static fn (): Session => Session::start(1, new \DateTimeImmutable(), $twice),
            static fn (): Session => Session::reconstitute(1, new \DateTimeImmutable(), [], null, $twice),
            static fn (): Scene => Scene::reconstitute(1, 'Gate', new \DateTimeImmutable(), characters: $twice),
            static fn (): Scene => Scene::start(1, 'Gate', new \DateTimeImmutable())->withCharacters($twice),
        ] as $build) {
            try {
                $build();
                self::fail('A Character was listed twice.');
            } catch (InvalidSessionParty|InvalidSceneCast $exception) {
                self::assertStringContainsString('"vi" is in the', $exception->getMessage());
            }
        }
    }

    private function campaign(): Campaign
    {
        $campaign = Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable());
        foreach (['vi', 'jax'] as $id) {
            $campaign->addCharacter($this->id($id), ucfirst($id), [], [], [], $this->release);
        }

        return $campaign;
    }

    /**
     * @phpstan-impure the campaign changes between calls
     *
     * @return list<string> the NPC ids in the current scene's cast
     */
    private function sceneCast(Campaign $campaign): array
    {
        return array_map(static fn (NpcId $id): string => $id->toString(), $campaign->currentScene()?->cast() ?? []);
    }

    /**
     * @phpstan-impure the campaign changes between calls
     *
     * @return list<string> the Character ids in the current scene's cast
     */
    private function sceneCharacters(Campaign $campaign): array
    {
        return $this->ids($campaign->currentScene()?->characters() ?? []);
    }

    /**
     * @return list<string>
     */
    private function firstSessionParty(Campaign $campaign): array
    {
        return $this->ids(array_first($campaign->sessions())?->party() ?? []);
    }

    private function id(string $id): CharacterId
    {
        return CharacterId::fromString($id);
    }

    /**
     * @param list<CharacterId> $ids
     *
     * @return list<string>
     */
    private function ids(array $ids): array
    {
        return array_map(static fn (CharacterId $id): string => $id->toString(), $ids);
    }
}
