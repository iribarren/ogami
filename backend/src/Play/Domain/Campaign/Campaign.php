<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

use App\Play\Domain\Campaign\Characters\Character;
use App\Play\Domain\Campaign\Characters\CharacterAlreadyExists;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\CharacterValues;
use App\Play\Domain\Campaign\Characters\InvalidCharacter;
use App\Play\Domain\Campaign\Characters\UnknownCharacter;
use App\Play\Domain\Campaign\Facts\Fact;
use App\Play\Domain\Campaign\Facts\FactAlreadyExists;
use App\Play\Domain\Campaign\Facts\FactFill;
use App\Play\Domain\Campaign\Facts\FactId;
use App\Play\Domain\Campaign\Facts\FactLink;
use App\Play\Domain\Campaign\Facts\FactLinkKind;
use App\Play\Domain\Campaign\Facts\InvalidFact;
use App\Play\Domain\Campaign\Facts\UnknownFact;
use App\Play\Domain\Campaign\Facts\UnknownFactSlot;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunContext;
use App\Play\Domain\Campaign\FlowRun\FlowRunNotActive;
use App\Play\Domain\Campaign\FlowRun\FlowRunPositionMismatch;
use App\Play\Domain\Campaign\FlowRun\FlowRunStatus;
use App\Play\Domain\Campaign\FlowRun\FlowRunView;
use App\Play\Domain\Campaign\FlowRun\InvalidStepResult;
use App\Play\Domain\Campaign\FlowRun\MoveOnNotAllowed;
use App\Play\Domain\Campaign\FlowRun\PickCandidate;
use App\Play\Domain\Campaign\FlowRun\PickCandidates;
use App\Play\Domain\Campaign\FlowRun\PickedItems;
use App\Play\Domain\Campaign\FlowRun\SceneTypeNotOffered;
use App\Play\Domain\Campaign\FlowRun\SessionCannotEndWhileGuided;
use App\Play\Domain\Campaign\FlowRun\StepCannotBeSkipped;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\Campaign\Lists\GeneratedItemId;
use App\Play\Domain\Campaign\Lists\InvalidNpc;
use App\Play\Domain\Campaign\Lists\InvalidThread;
use App\Play\Domain\Campaign\Lists\ItemRules;
use App\Play\Domain\Campaign\Lists\Npc;
use App\Play\Domain\Campaign\Lists\NpcAlreadyExists;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\Thread;
use App\Play\Domain\Campaign\Lists\ThreadAlreadyExists;
use App\Play\Domain\Campaign\Lists\ThreadId;
use App\Play\Domain\Campaign\Lists\UnknownNpc;
use App\Play\Domain\Campaign\Lists\UnknownReleaseTag;
use App\Play\Domain\Campaign\Lists\UnknownThread;
use App\Play\Domain\GameSystem\FactSlot;
use App\Play\Domain\GameSystem\Flow\Flow;
use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Play\Domain\GameSystem\Flow\PickStep;
use App\Play\Domain\GameSystem\Flow\TrackerOperation;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\SceneType;
use App\Play\Domain\GameSystem\SnapshotLikelihoodOracle;
use App\Play\Domain\GameSystem\Tracker;
use App\Play\Domain\GameSystem\UnknownFlow;
use App\Randomness\Domain\Oracle\OracleTableResult;

/**
 * A solo player's ongoing game, pinned to one GameSystem release (ADR 0014), played freely or along
 * one Flow of that release (its key; chosen at creation). Play is organized in sessions (sittings)
 * and scenes: the current session is the latest one while it has not ended, the current scene is
 * the latest scene of the current session. Once the latest session ends there is no current session
 * or scene until the next session starts. A scene of play may have a Scene Type of the pinned
 * release; a hook Scene records a phase boundary.
 *
 * The campaign holds a value for every Tracker of its pinned release (none for schema version 1).
 * The definitions stay in the release: methods that read or change a value take the Tracker and
 * clamp to its range. A Tracker without a stored value (a schema version 2 campaign stored before
 * campaigns held tracker values) reads as its starting value until it changes.
 *
 * A campaign created with a Flow holds its FlowRun, which guides play along it; the FlowRun commands
 * take the pinned release and change the campaign all or nothing. Hand Tracker edits and Scene Type
 * switches are recorded in its history.
 *
 * It also keeps the player's NPCs and Threads (at most 500 of each), added, edited, closed and
 * reopened at any time. Their release Tags are keys of the pinned release. Lists keep creation
 * order; the readers put open items first. A Scene's cast lists NPCs of the campaign: picks and
 * created NPCs join the current scene's, the player edits it.
 *
 * It keeps Campaign facts too (at most 500): free facts the player writes, and slot facts that fill
 * the Fact slots of the pinned release, each with Fact links to NPCs, Threads and other facts. A
 * slot holds at most one fact; links and subjects name things of this campaign. Facts keep creation
 * order; the reader puts slot facts first, in release slot order.
 *
 * State is kept as scalars (id, owner, name, pinned release fields, Flow key) plus the session list, the
 * tracker values, the NPC and Thread lists and the FlowRun, so an adapter can map it and rebuild it
 * with reconstitute(). Every change to the FlowRun replaces it with a changed copy, as changes to
 * sessions, NPCs and Threads replace the list (and the changed item), so that an adapter comparing
 * stored values by identity sees it.
 */
final class Campaign
{
    public const int MAX_NAME_LENGTH = 100;

    public const int MAX_SESSIONS = 500;

    public const int MAX_NPCS = 500;

    public const int MAX_THREADS = 500;

    public const int MAX_FACTS = 500;

    public const int MAX_CHARACTERS = 100;

    /**
     * Concurrency token, owned by the persistence adapter: it counts saves so that a save of a
     * stale copy is refused (CampaignModifiedConcurrently). The model never changes it.
     */
    private int $version = 1;

    private ?FlowRun $flowRun = null;

    /**
     * @param list<Session>      $sessions      in number order
     * @param array<string, int> $trackerValues by Tracker key
     * @param list<Npc>          $npcs          in creation order
     * @param list<Thread>       $threads       in creation order
     * @param list<Fact>         $facts         in creation order
     * @param list<Character>    $characters    in creation order
     */
    private function __construct(
        private readonly string $id,
        private readonly string $ownerId,
        private readonly string $name,
        private readonly string $gameSystemKey,
        private readonly int $releaseVersion,
        private readonly string $gameSystemName,
        private readonly \DateTimeImmutable $createdAt,
        private array $sessions,
        private array $trackerValues,
        private readonly ?string $flowKey,
        private array $npcs = [],
        private array $threads = [],
        private array $facts = [],
        private array $characters = [],
    ) {
    }

    /**
     * @param list<Tracker> $trackers the Trackers of the pinned release: counters start at their initial value, clocks at 0
     * @param ?Flow         $flow     a Flow of the pinned release to play along, or null to play freely
     *
     * @throws InvalidCampaignOwner when the owner id is blank
     * @throws InvalidCampaignName  when the trimmed name is blank or longer than 100 characters
     */
    public static function create(CampaignId $id, string $ownerId, string $name, PinnedRelease $pinnedRelease, \DateTimeImmutable $createdAt, array $trackers = [], ?Flow $flow = null): self
    {
        if ('' === trim($ownerId)) {
            throw InvalidCampaignOwner::blank();
        }

        $name = trim($name);
        if ('' === $name) {
            throw InvalidCampaignName::blank();
        }

        $length = mb_strlen($name);
        if ($length > self::MAX_NAME_LENGTH) {
            throw InvalidCampaignName::tooLong(self::MAX_NAME_LENGTH, $length);
        }

        $trackerValues = [];
        foreach ($trackers as $tracker) {
            $trackerValues[$tracker->key] = $tracker->initial;
        }

        return self::reconstitute($id, $ownerId, $name, $pinnedRelease, $createdAt, [], $trackerValues, $flow?->key, $flow instanceof Flow ? FlowRun::start() : null);
    }

    /**
     * Rebuilds a stored campaign, e.g. from persistence. No rule is checked again, except that the
     * NPCs, the Threads and the facts each have distinct ids (the invariant an id lookup relies on)
     * and that the facts hold together (see assertFactsConsistent()).
     *
     * @param list<Session>      $sessions      in number order
     * @param array<string, int> $trackerValues by Tracker key
     * @param ?string            $flowKey       the key of the Flow played, or null for free play
     * @param ?FlowRun           $flowRun       the guidance along that Flow, null for free play; a campaign
     *                                          with a Flow and no FlowRun gets a fresh one (see startMissingFlowRun())
     * @param list<Npc>          $npcs          in creation order
     * @param list<Thread>       $threads       in creation order
     * @param list<Fact>         $facts         in creation order
     * @param list<Character>    $characters    in creation order
     *
     * @throws NpcAlreadyExists       when two NPCs share an id
     * @throws CharacterAlreadyExists when two Characters share an id
     * @throws ThreadAlreadyExists    when two Threads share an id
     * @throws FactAlreadyExists      when two facts share an id
     * @throws CampaignLimitReached   when there are more than 500 facts
     * @throws InvalidFact            when two facts fill one slot, or a fact links to itself
     * @throws UnknownNpc             when a link or subject names an NPC the campaign does not hold
     * @throws UnknownThread          when a link or subject names a Thread the campaign does not hold
     * @throws UnknownFact            when a link names a fact the campaign does not hold
     */
    public static function reconstitute(CampaignId $id, string $ownerId, string $name, PinnedRelease $pinnedRelease, \DateTimeImmutable $createdAt, array $sessions, array $trackerValues = [], ?string $flowKey = null, ?FlowRun $flowRun = null, array $npcs = [], array $threads = [], array $facts = [], array $characters = []): self
    {
        $npcIds = [];
        foreach ($npcs as $npc) {
            $key = $npc->id()->toString();
            if (isset($npcIds[$key])) {
                throw NpcAlreadyExists::withId($npc->id());
            }

            $npcIds[$key] = true;
        }

        $threadIds = [];
        foreach ($threads as $thread) {
            $key = $thread->id()->toString();
            if (isset($threadIds[$key])) {
                throw ThreadAlreadyExists::withId($thread->id());
            }

            $threadIds[$key] = true;
        }

        $characterIds = [];
        foreach ($characters as $character) {
            $key = $character->id()->toString();
            if (isset($characterIds[$key])) {
                throw CharacterAlreadyExists::withId($character->id());
            }

            $characterIds[$key] = true;
        }

        $campaign = new self(
            $id->toString(),
            $ownerId,
            $name,
            $pinnedRelease->gameSystemKey(),
            $pinnedRelease->releaseVersion(),
            $pinnedRelease->gameSystemName(),
            $createdAt,
            $sessions,
            $trackerValues,
            $flowKey,
            $npcs,
            $threads,
            $facts,
            $characters,
        );
        $campaign->flowRun = $flowRun;
        $campaign->startMissingFlowRun();
        $campaign->assertFactsConsistent();

        return $campaign;
    }

    /**
     * Gives a campaign with a Flow and no stored FlowRun a fresh one, which waits for the next
     * session. Such campaigns were stored before FlowRuns existed (play-flow-run T10a). Called by
     * reconstitute() and by the persistence adapter after it loads a campaign; free play and a
     * campaign that has a FlowRun stay as they are.
     */
    public function startMissingFlowRun(): void
    {
        if (null !== $this->flowKey && !$this->flowRun instanceof FlowRun) {
            $this->flowRun = FlowRun::start();
        }
    }

    /**
     * Starts session n + 1 (the first is 1). It becomes the current session, with no scene yet;
     * a session still under way stays as it is. A guided FlowRun goes on in it: its session opening
     * runs (in a hook Scene), then a scene pick with one Scene Type starts that scene; a guided
     * scene or hook Scene of an earlier session is abandoned. All or nothing, like the FlowRun
     * commands.
     *
     * @param ?GameSystemSnapshot $release the pinned release; without it a FlowRun is not told
     *
     * @throws CampaignLimitReached when the campaign already holds 500 sessions
     */
    public function startSession(\DateTimeImmutable $startedAt, ?GameSystemSnapshot $release = null): Session
    {
        if (\count($this->sessions) >= self::MAX_SESSIONS) {
            throw CampaignLimitReached::sessions(self::MAX_SESSIONS);
        }

        $session = Session::start(\count($this->sessions) + 1, $startedAt);
        $draft = clone $this;
        $draft->sessions[] = $session;
        if ($release instanceof GameSystemSnapshot) {
            $draft->changedFlowRun()?->sessionStarted($draft->flowContext($release, $startedAt));
        }

        $this->adopt($draft);

        return $session;
    }

    /**
     * Ends the current session: its scenes stay, and no scene starts until the next session. While
     * guidance is active it ends only at the scene pick, after the session closing (in its hook
     * Scene): until that finishes the session stays under way. All or nothing, like the FlowRun
     * commands.
     *
     * @param ?int                $number  the number of the session the caller means to end, or null for whichever is under way
     * @param ?GameSystemSnapshot $release the pinned release; without it a FlowRun is not told and the session ends at once
     *
     * @return Session the session, ended unless its session closing runs first
     *
     * @throws NoCurrentSession            when no session is under way (none has started, or it has
     *                                     ended), or another session than the named one is
     * @throws SessionCannotEndWhileGuided when guidance is active and the FlowRun is in a scene or a hook
     * @throws CampaignLimitReached        when the session closing's hook Scene does not fit the session
     */
    public function endSession(\DateTimeImmutable $endedAt, ?int $number = null, ?GameSystemSnapshot $release = null): Session
    {
        $session = $this->currentSession() ?? throw NoCurrentSession::toEndSession();
        if (null !== $number && $session->number() !== $number) {
            throw NoCurrentSession::numbered($number);
        }

        if ($release instanceof GameSystemSnapshot && FlowRunStatus::Active === $this->flowRun?->status()) {
            $draft = clone $this;
            $draft->guided()->endSession($draft->flowContext($release, $endedAt));
            $this->adopt($draft);

            return $this->sessions[$session->number() - 1];
        }

        $ended = $session->end($endedAt);
        $this->sessions = [...\array_slice($this->sessions, 0, -1), $ended];

        return $ended;
    }

    /**
     * Starts scene m + 1 of the current session (the first is 1), a scene of play. It becomes the
     * current scene.
     *
     * Without a title, a scene with a Scene Type is named after it, numbered per Scene Type across
     * the campaign ("Legwork 2" for the second Legwork scene); a title given wins.
     *
     * @param ?SceneType $sceneType a Scene Type of the pinned release, or null
     *
     * @throws NoCurrentSession     when no session is under way
     * @throws CampaignLimitReached when the current session already holds 200 scenes
     * @throws InvalidSceneTitle    when the trimmed title is blank or longer than 100 characters, or
     *                              there is neither a title nor a Scene Type
     */
    public function startScene(?string $title, \DateTimeImmutable $startedAt, ?SceneType $sceneType = null): Scene
    {
        $title ??= $sceneType instanceof SceneType ? $this->defaultSceneTitle($sceneType) : throw InvalidSceneTitle::missing();

        return $this->addScene(static fn (Session $session): Session => $session->withNewScene($title, $startedAt, $sceneType?->key));
    }

    /**
     * Starts scene m + 1 of the current session as a hook Scene (see Hook::defaultTitle() for the
     * titles a FlowRun gives). It becomes the current scene.
     *
     * @throws NoCurrentSession     when no session is under way
     * @throws CampaignLimitReached when the current session already holds 200 scenes
     * @throws InvalidSceneTitle    when the trimmed title is blank or longer than 100 characters
     */
    public function startHookScene(Hook $hook, string $title, \DateTimeImmutable $startedAt): Scene
    {
        return $this->addScene(static fn (Session $session): Session => $session->withNewHookScene($hook, $title, $startedAt));
    }

    /**
     * Switches the current scene to another Scene Type by hand (free play). Its number, title and
     * journal entries stay, a default numbered title included. With a FlowRun the switch is in its
     * history, and while guidance is active a guided scene goes on at the new type's setup.
     *
     * @param SceneType           $sceneType a Scene Type of the pinned release
     * @param ?GameSystemSnapshot $release   the pinned release, with the time of the switch; without
     *                                       them a FlowRun is not told (see startSession())
     *
     * @throws NoCurrentScene          when the current session has no scene, or no session is under way
     * @throws HookSceneHasNoSceneType when the current scene is a hook Scene
     */
    public function switchSceneType(SceneType $sceneType, ?GameSystemSnapshot $release = null, ?\DateTimeImmutable $at = null): Scene
    {
        $session = $this->currentSession();
        $scene = $session?->currentScene() ?? throw NoCurrentScene::toSwitchSceneType();
        $switched = $scene->withSceneType($sceneType->key);
        $this->sessions = [...\array_slice($this->sessions, 0, -1), $session->withCurrentScene($switched)];
        if ($release instanceof GameSystemSnapshot && $at instanceof \DateTimeImmutable) {
            $this->changedFlowRun()?->followSceneTypeSwitch($scene->sceneType(), $sceneType, $this->flowContext($release, $at));
        }

        return $switched;
    }

    /**
     * Renames the current scene (a sceneTitle Effect of the FlowRun).
     *
     * @throws NoCurrentScene    when the current session has no scene, or no session is under way
     * @throws InvalidSceneTitle when the trimmed title is blank or longer than 100 characters
     */
    public function renameCurrentScene(string $title): Scene
    {
        $session = $this->currentSession();
        $renamed = ($session?->currentScene() ?? throw NoCurrentScene::toRename())->withTitle($title);
        $this->sessions = [...\array_slice($this->sessions, 0, -1), $session->withCurrentScene($renamed)];

        return $renamed;
    }

    /**
     * Replaces the current scene's cast (the player's edit; any NPC of the campaign, closed ones
     * included). All or nothing.
     *
     * @param list<NpcId> $cast
     *
     * @throws NoCurrentScene   when the current session has no scene, or no session is under way
     * @throws UnknownNpc       when an id is not an NPC of the campaign
     * @throws InvalidSceneCast when an NPC is listed twice
     */
    public function editCurrentSceneCast(array $cast): Scene
    {
        $session = $this->currentSession();
        $scene = $session?->currentScene() ?? throw NoCurrentScene::toEditCast();
        foreach ($cast as $id) {
            if (!$this->npc($id) instanceof Npc) {
                throw UnknownNpc::withId($id);
            }
        }

        $edited = $scene->withCast($cast);
        $this->sessions = [...\array_slice($this->sessions, 0, -1), $session->withCurrentScene($edited)];

        return $edited;
    }

    /**
     * Adds NPCs to the end of the current scene's cast, those already in it staying where they are
     * (a pick step or a createNpc Effect of the FlowRun).
     *
     * @param list<NpcId> $ids NPCs of the campaign
     *
     * @throws NoCurrentScene when the current session has no scene, or no session is under way
     */
    public function addToCurrentSceneCast(array $ids): void
    {
        $scene = $this->currentScene() ?? throw NoCurrentScene::toEditCast();
        $cast = $scene->cast();
        foreach ($ids as $id) {
            if (!array_any($cast, static fn (NpcId $member): bool => $member->equals($id))) {
                $cast[] = $id;
            }
        }

        $this->editCurrentSceneCast($cast);
    }

    /**
     * Sets a Tracker's value by hand, clamped to its range. With a FlowRun the edit is in its history.
     *
     * @param ?\DateTimeImmutable $at when; without it a FlowRun is not told (see startSession())
     *
     * @return int the value kept
     */
    public function setTrackerValue(Tracker $tracker, int $value, ?\DateTimeImmutable $at = null): int
    {
        $from = $this->trackerValue($tracker);
        $kept = $this->applyTrackerChange($tracker, TrackerOperation::Set, $value);
        if ($at instanceof \DateTimeImmutable) {
            $this->changedFlowRun()?->recordTrackerEdit($tracker->key, $from, $kept, $at);
        }

        return $kept;
    }

    /**
     * Adds to a Tracker's value or sets it, clamped to its range.
     *
     * @return int the value kept
     */
    public function applyTrackerChange(Tracker $tracker, TrackerOperation $operation, int $value): int
    {
        $current = $this->trackerValue($tracker);

        return $this->trackerValues[$tracker->key] = $tracker->clamp(TrackerOperation::Add === $operation ? $current + $value : $value);
    }

    /**
     * The chaos factor to ask a likelihood oracle with: the campaign's value of the Tracker its
     * chaos is bound to, else the requested one (null for the oracle's neutral factor).
     *
     * @param GameSystemSnapshot $release the pinned release, which declares the bound Tracker
     *
     * @throws ChaosFactorBoundToTracker when the oracle is bound and a chaos factor is requested
     * @throws UnknownCampaignTracker    when the release declares no Tracker with the bound key
     */
    public function chaosFactorFor(SnapshotLikelihoodOracle $oracle, ?int $requested, GameSystemSnapshot $release): ?int
    {
        $key = $oracle->chaosTracker();
        if (null === $key) {
            return $requested;
        }

        if (null !== $requested) {
            throw ChaosFactorBoundToTracker::for($oracle->key(), $key);
        }

        return $this->trackerValue($release->tracker($key) ?? throw UnknownCampaignTracker::withKey($key));
    }

    /**
     * @return array<string, int> the stored value of each Tracker by key; release order is not kept,
     *                            and a Tracker without a stored value is missing (see trackerValue())
     */
    public function trackerValues(): array
    {
        return $this->trackerValues;
    }

    /**
     * The campaign's value of a Tracker of its pinned release: the stored one, else the Tracker's
     * starting value (initial for a counter, 0 for a clock).
     */
    public function trackerValue(Tracker $tracker): int
    {
        return $this->trackerValues[$tracker->key] ?? $tracker->initial;
    }

    /**
     * Adds an open Npc at the end of the list.
     *
     * @param list<string> $tags     release Tag keys of the pinned release
     * @param list<string> $freeTags
     *
     * @throws InvalidNpc           when a value breaks a rule
     * @throws UnknownReleaseTag    when a Tag key is not in the pinned release
     * @throws NpcAlreadyExists     when the campaign holds an Npc with this id
     * @throws CampaignLimitReached when the campaign already holds 500 Npcs
     */
    public function addNpc(NpcId $id, string $name, ?string $agenda, array $tags, array $freeTags, int $weight, GameSystemSnapshot $release): Npc
    {
        $this->assertPinned($release);
        if (\count($this->npcs) >= self::MAX_NPCS) {
            throw CampaignLimitReached::npcs(self::MAX_NPCS);
        }

        if ($this->npc($id) instanceof Npc) {
            throw NpcAlreadyExists::withId($id);
        }

        $npc = Npc::of($id, $name, $agenda, $tags, $freeTags, $weight);
        ItemRules::assertInRelease($npc->tags(), $release);
        $this->npcs = [...$this->npcs, $npc];

        return $npc;
    }

    /**
     * Replaces the Npc's name, agenda, Tags, free tags and weight; its status stays. All or nothing.
     *
     * @param list<string> $tags     release Tag keys of the pinned release
     * @param list<string> $freeTags
     *
     * @throws UnknownNpc        when the campaign holds no Npc with this id
     * @throws InvalidNpc        when a value breaks a rule
     * @throws UnknownReleaseTag when a Tag key is not in the pinned release
     */
    public function editNpc(NpcId $id, string $name, ?string $agenda, array $tags, array $freeTags, int $weight, GameSystemSnapshot $release): Npc
    {
        $this->assertPinned($release);
        $index = $this->npcIndex($id);
        $edited = $this->npcs[$index]->edited($name, $agenda, $tags, $freeTags, $weight);
        ItemRules::assertInRelease($edited->tags(), $release);
        $this->npcs = $this->replaced($this->npcs, $index, $edited);

        return $edited;
    }

    /**
     * Closes the Npc: it is no longer picked or counted. Closing a closed one changes nothing.
     *
     * @throws UnknownNpc when the campaign holds no Npc with this id
     */
    public function closeNpc(NpcId $id): void
    {
        $index = $this->npcIndex($id);
        if ($this->npcs[$index]->isOpen()) {
            $this->npcs = $this->replaced($this->npcs, $index, $this->npcs[$index]->closed());
        }
    }

    /**
     * Reopens the Npc. Reopening an open one changes nothing.
     *
     * @throws UnknownNpc when the campaign holds no Npc with this id
     */
    public function reopenNpc(NpcId $id): void
    {
        $index = $this->npcIndex($id);
        if (!$this->npcs[$index]->isOpen()) {
            $this->npcs = $this->replaced($this->npcs, $index, $this->npcs[$index]->reopened());
        }
    }

    public function npc(NpcId $id): ?Npc
    {
        return $this->npcs[$this->npcIndexOrNull($id) ?? -1] ?? null;
    }

    /**
     * @return list<Npc> the open ones first, then the closed ones, each in creation order
     */
    public function npcs(): array
    {
        return $this->openFirst($this->npcs);
    }

    /**
     * Adds a Character at the end of the list. Values not given take the field default; the
     * Character stores one value per field of the pinned release's sheet.
     *
     * @param list<string> $tags     release Tag keys of the pinned release
     * @param list<string> $freeTags
     * @param array<mixed> $values   field key to value
     *
     * @throws InvalidCharacter       when a value breaks a rule or does not fit the sheet
     * @throws UnknownReleaseTag      when a Tag key is not in the pinned release
     * @throws CharacterAlreadyExists when the campaign holds a Character with this id
     * @throws CampaignLimitReached   when the campaign already holds 100 Characters
     */
    public function addCharacter(CharacterId $id, string $name, array $tags, array $freeTags, array $values, GameSystemSnapshot $release): Character
    {
        $this->assertPinned($release);
        if (\count($this->characters) >= self::MAX_CHARACTERS) {
            throw CampaignLimitReached::characters(self::MAX_CHARACTERS);
        }

        if ($this->character($id) instanceof Character) {
            throw CharacterAlreadyExists::withId($id);
        }

        $character = Character::of($id, $name, $tags, $freeTags, CharacterValues::resolve($release->sheet(), $values));
        ItemRules::assertInRelease($character->tags(), $release);
        $this->characters = [...$this->characters, $character];

        return $character;
    }

    /**
     * Replaces the Character's name, Tags, free tags and values; the NPC it was promoted from stays.
     * The values replace the old ones as a whole: a field not given goes back to its default. All
     * or nothing.
     *
     * @param list<string> $tags     release Tag keys of the pinned release
     * @param list<string> $freeTags
     * @param array<mixed> $values   field key to value
     *
     * @throws UnknownCharacter  when the campaign holds no Character with this id
     * @throws InvalidCharacter  when a value breaks a rule or does not fit the sheet
     * @throws UnknownReleaseTag when a Tag key is not in the pinned release
     */
    public function editCharacter(CharacterId $id, string $name, array $tags, array $freeTags, array $values, GameSystemSnapshot $release): Character
    {
        $this->assertPinned($release);
        $index = $this->characterIndex($id);
        $edited = $this->characters[$index]->edited($name, $tags, $freeTags, CharacterValues::resolve($release->sheet(), $values));
        ItemRules::assertInRelease($edited->tags(), $release);
        $this->characters = $this->replaced($this->characters, $index, $edited);

        return $edited;
    }

    public function character(CharacterId $id): ?Character
    {
        return $this->characters[$this->characterIndexOrNull($id) ?? -1] ?? null;
    }

    /**
     * @return list<Character> in creation order
     */
    public function characters(): array
    {
        return $this->characters;
    }

    /**
     * Adds an open NPC with weight 1 and no free tags, as a createNpc Effect of the FlowRun does,
     * under a generated id (see GeneratedItemId).
     *
     * @param list<string> $tags release Tag keys of the pinned release
     *
     * @return ?Npc the new NPC; null (nothing added) when the campaign already holds 500 NPCs
     *
     * @throws InvalidNpc        when a value breaks a rule
     * @throws UnknownReleaseTag when a Tag key is not in the pinned release
     * @throws NpcAlreadyExists  when an NPC already holds the generated id
     */
    public function createNpcByEffect(string $name, ?string $agenda, array $tags, GameSystemSnapshot $release): ?Npc
    {
        if (\count($this->npcs) >= self::MAX_NPCS) {
            return null;
        }

        return $this->addNpc(GeneratedItemId::npc($this->id, \count($this->npcs)), $name, $agenda, $tags, [], ItemRules::DEFAULT_WEIGHT, $release);
    }

    /**
     * Adds an open Thread with weight 1 and no free tags, as a createThread Effect of the FlowRun
     * does, under a generated id (see GeneratedItemId).
     *
     * @param list<string> $tags release Tag keys of the pinned release
     *
     * @return ?Thread the new Thread; null (nothing added) when the campaign already holds 500 Threads
     *
     * @throws InvalidThread       when a value breaks a rule
     * @throws UnknownReleaseTag   when a Tag key is not in the pinned release
     * @throws ThreadAlreadyExists when a Thread already holds the generated id
     */
    public function createThreadByEffect(string $name, array $tags, GameSystemSnapshot $release): ?Thread
    {
        if (\count($this->threads) >= self::MAX_THREADS) {
            return null;
        }

        return $this->addThread(GeneratedItemId::thread($this->id, \count($this->threads)), $name, $tags, [], ItemRules::DEFAULT_WEIGHT, $release);
    }

    /**
     * Fills a Fact slot of the pinned release as a fillFact Effect of the FlowRun does: a filled
     * slot takes the new value (its fact id and links stay); an unfilled one gets a new slot fact
     * under a generated id (see GeneratedItemId).
     *
     * @param ?string   $text    the value of a text slot
     * @param ?FactLink $subject the value of an NPC or Thread slot
     *
     * @return ?Fact the slot fact; null (nothing added) when a new one would pass 500 facts
     *
     * @throws \LogicException   when the pinned release declares no slot with this key
     * @throws InvalidFact       when the value breaks a rule
     * @throws UnknownNpc        when the subject is an NPC the campaign does not hold
     * @throws UnknownThread     when the subject is a Thread the campaign does not hold
     * @throws FactAlreadyExists when a new fact's generated id is already held
     */
    public function fillFactByEffect(string $slot, ?string $text, ?FactLink $subject, GameSystemSnapshot $release): ?Fact
    {
        $this->assertPinned($release);
        $release->factSlot($slot) ?? throw new \LogicException(\sprintf('The pinned release has no Fact slot "%s".', $slot));
        if ($subject instanceof FactLink) {
            $this->assertLinkResolves($subject);
        }

        $index = $this->factIndexOfSlot($slot);
        if (null !== $index) {
            $fact = $this->facts[$index]->refilled($text, $subject);
            $this->facts = $this->replaced($this->facts, $index, $fact);

            return $fact;
        }

        if (\count($this->facts) >= self::MAX_FACTS) {
            return null;
        }

        $id = GeneratedItemId::fact($this->id, \count($this->facts));
        $fact = $this->newFact($id, Fact::of($id, $slot, null, $text, $subject, []));
        $this->facts = [...$this->facts, $fact];

        return $fact;
    }

    /**
     * The value of every Fact slot of the pinned release, by slot key, read now: the slot fact's
     * text, or the current name of its subject; '' while the slot is unfilled (or its subject is
     * gone). What "{fact:key}" renders.
     *
     * @return array<string, string>
     */
    public function factValues(GameSystemSnapshot $release): array
    {
        $values = [];
        foreach ($release->factSlots() as $slot) {
            $fact = $this->facts[$this->factIndexOfSlot($slot->key) ?? -1] ?? null;
            $subject = $fact?->subject();
            $values[$slot->key] = $subject instanceof FactLink
                ? ($this->itemNames(FactLinkKind::Npc === $subject->kind ? ItemKind::Npc : ItemKind::Thread, [$subject->id])[0] ?? '')
                : ($fact?->text() ?? '');
        }

        return $values;
    }

    /**
     * Closes the open Threads among these ids (a closeThread Effect of the FlowRun); closed ones
     * stay as they are, and an id the campaign does not hold is skipped.
     *
     * @param list<string> $ids
     */
    public function closeThreadsById(array $ids): void
    {
        foreach ($ids as $id) {
            $thread = $this->thread(ThreadId::fromString($id));
            if ($thread instanceof Thread) {
                $this->closeThread($thread->id());
            }
        }
    }

    /**
     * Adds an open Thread at the end of the list.
     *
     * @param list<string> $tags     release Tag keys of the pinned release
     * @param list<string> $freeTags
     *
     * @throws InvalidThread        when a value breaks a rule
     * @throws UnknownReleaseTag    when a Tag key is not in the pinned release
     * @throws ThreadAlreadyExists  when the campaign holds an Thread with this id
     * @throws CampaignLimitReached when the campaign already holds 500 Threads
     */
    public function addThread(ThreadId $id, string $name, array $tags, array $freeTags, int $weight, GameSystemSnapshot $release): Thread
    {
        $this->assertPinned($release);
        if (\count($this->threads) >= self::MAX_THREADS) {
            throw CampaignLimitReached::threads(self::MAX_THREADS);
        }

        if ($this->thread($id) instanceof Thread) {
            throw ThreadAlreadyExists::withId($id);
        }

        $thread = Thread::of($id, $name, $tags, $freeTags, $weight);
        ItemRules::assertInRelease($thread->tags(), $release);
        $this->threads = [...$this->threads, $thread];

        return $thread;
    }

    /**
     * Replaces the Thread's name, Tags, free tags and weight; its status stays. All or nothing.
     *
     * @param list<string> $tags     release Tag keys of the pinned release
     * @param list<string> $freeTags
     *
     * @throws UnknownThread     when the campaign holds no Thread with this id
     * @throws InvalidThread     when a value breaks a rule
     * @throws UnknownReleaseTag when a Tag key is not in the pinned release
     */
    public function editThread(ThreadId $id, string $name, array $tags, array $freeTags, int $weight, GameSystemSnapshot $release): Thread
    {
        $this->assertPinned($release);
        $index = $this->threadIndex($id);
        $edited = $this->threads[$index]->edited($name, $tags, $freeTags, $weight);
        ItemRules::assertInRelease($edited->tags(), $release);
        $this->threads = $this->replaced($this->threads, $index, $edited);

        return $edited;
    }

    /**
     * Closes the Thread: it is no longer picked or counted. Closing a closed one changes nothing.
     *
     * @throws UnknownThread when the campaign holds no Thread with this id
     */
    public function closeThread(ThreadId $id): void
    {
        $index = $this->threadIndex($id);
        if ($this->threads[$index]->isOpen()) {
            $this->threads = $this->replaced($this->threads, $index, $this->threads[$index]->closed());
        }
    }

    /**
     * Reopens the Thread. Reopening an open one changes nothing.
     *
     * @throws UnknownThread when the campaign holds no Thread with this id
     */
    public function reopenThread(ThreadId $id): void
    {
        $index = $this->threadIndex($id);
        if (!$this->threads[$index]->isOpen()) {
            $this->threads = $this->replaced($this->threads, $index, $this->threads[$index]->reopened());
        }
    }

    public function thread(ThreadId $id): ?Thread
    {
        return $this->threads[$this->threadIndexOrNull($id) ?? -1] ?? null;
    }

    /**
     * @return list<Thread> the open ones first, then the closed ones, each in creation order
     */
    public function threads(): array
    {
        return $this->openFirst($this->threads);
    }

    /**
     * Adds a free Campaign fact at the end of the list.
     *
     * @param list<FactLink> $links
     *
     * @throws InvalidFact          when a value breaks a rule
     * @throws UnknownNpc           when a link names an NPC the campaign does not hold
     * @throws UnknownThread        when a link names a Thread the campaign does not hold
     * @throws UnknownFact          when a link names a fact the campaign does not hold
     * @throws FactAlreadyExists    when the campaign holds a fact with this id
     * @throws CampaignLimitReached when the campaign already holds 500 facts
     */
    public function addFreeFact(FactId $id, string $label, string $text, array $links, GameSystemSnapshot $release): Fact
    {
        $this->assertPinned($release);
        $fact = $this->newFact($id, Fact::of($id, null, $label, $text, null, $links));
        $this->facts = [...$this->facts, $fact];

        return $fact;
    }

    /**
     * Fills a Fact slot of the pinned release by hand: a text, an existing NPC or Thread (closed
     * ones too), or a new NPC or Thread by name, created in the same call (open, weight 1, no
     * tags). An unfilled slot gets a new fact under the given id; a filled one keeps its id and
     * links and takes the new value. All or nothing: a refusal creates nothing, not even the NPC.
     *
     * @throws UnknownFactSlot      when the pinned release declares no slot with this key
     * @throws InvalidFact          when the value does not fit the slot's type, or breaks a rule
     * @throws UnknownNpc           when the subject is an NPC the campaign does not hold
     * @throws UnknownThread        when the subject is a Thread the campaign does not hold
     * @throws FactAlreadyExists    when a new fact's id is already held
     * @throws CampaignLimitReached when a new fact or NPC or Thread would pass its 500 limit
     * @throws InvalidNpc           when a new NPC's name breaks a rule
     * @throws InvalidThread        when a new Thread's name breaks a rule
     * @throws NpcAlreadyExists     when a new NPC's id is already held
     * @throws ThreadAlreadyExists  when a new Thread's id is already held
     */
    public function fillFactSlot(FactId $id, string $slot, FactFill $fill, GameSystemSnapshot $release): Fact
    {
        $this->assertPinned($release);
        $definition = $release->factSlot($slot) ?? throw UnknownFactSlot::withKey($slot);
        $fill->assertFits($definition->type);

        $draft = clone $this;
        $subject = $fill->subject;
        if (null !== $fill->newName && $subject instanceof FactLink) {
            if (FactLinkKind::Npc === $subject->kind) {
                $draft->addNpc(NpcId::fromString($subject->id), $fill->newName, null, [], [], ItemRules::DEFAULT_WEIGHT, $release);
            } else {
                $draft->addThread(ThreadId::fromString($subject->id), $fill->newName, [], [], ItemRules::DEFAULT_WEIGHT, $release);
            }
        }

        if ($subject instanceof FactLink) {
            $draft->assertLinkResolves($subject);
        }

        $index = $draft->factIndexOfSlot($slot);
        if (null === $index) {
            $fact = $draft->newFact($id, Fact::of($id, $slot, null, $fill->text, $subject, []));
            $draft->facts = [...$draft->facts, $fact];
        } else {
            $fact = $draft->facts[$index]->refilled($fill->text, $subject);
            $draft->facts = $this->replaced($draft->facts, $index, $fact);
        }

        $this->adopt($draft);

        return $fact;
    }

    /**
     * Edits a fact. A free fact takes a new label and text (a null keeps the current one) and new
     * links; a slot fact takes only new links (a label or text is refused: its value changes by
     * filling the slot again). The links replace the fact's links. All or nothing.
     *
     * @param list<FactLink> $links
     *
     * @throws UnknownFact   when the campaign holds no fact with this id, or a link names none
     * @throws InvalidFact   when a value breaks a rule
     * @throws UnknownNpc    when a link names an NPC the campaign does not hold
     * @throws UnknownThread when a link names a Thread the campaign does not hold
     */
    public function editFact(FactId $id, ?string $label, ?string $text, array $links, GameSystemSnapshot $release): Fact
    {
        $this->assertPinned($release);
        $index = $this->factIndex($id);
        $edited = $this->facts[$index]->edited($label, $text)->withLinks($links);
        foreach ($edited->links() as $link) {
            $this->assertLinkResolves($link);
        }

        $this->facts = $this->replaced($this->facts, $index, $edited);

        return $edited;
    }

    public function fact(FactId $id): ?Fact
    {
        return $this->facts[$this->factIndexOrNull($id) ?? -1] ?? null;
    }

    /**
     * @return list<Fact> the slot facts first, in the release's slot order, then the free facts in creation order
     */
    public function facts(GameSystemSnapshot $release): array
    {
        $order = array_flip(array_map(static fn (FactSlot $slot): string => $slot->key, $release->factSlots()));
        $slotFacts = array_values(array_filter($this->facts, static fn (Fact $fact): bool => !$fact->isFree()));
        usort($slotFacts, static fn (Fact $a, Fact $b): int => ($order[$a->slot() ?? ''] ?? \PHP_INT_MAX) <=> ($order[$b->slot() ?? ''] ?? \PHP_INT_MAX));

        return [...$slotFacts, ...array_filter($this->facts, static fn (Fact $fact): bool => $fact->isFree())];
    }

    /**
     * The number of open NPCs or Threads carrying all the given release Tag keys (any, when none);
     * free tags never match. What a count condition counts.
     *
     * @param list<string> $tags
     */
    public function openItemCount(ItemKind $of, array $tags): int
    {
        $items = ItemKind::Npc === $of ? $this->npcs : $this->threads;

        return \count(array_filter($items, static fn (Npc|Thread $item): bool => $item->isOpen() && $item->carries($tags)));
    }

    /**
     * The candidates of a pick step: the open NPCs or Threads carrying all the given release Tag
     * keys (any, when none), in list order; free tags never match.
     *
     * @param list<string> $tags
     */
    public function pickCandidates(ItemKind $of, array $tags): PickCandidates
    {
        $items = ItemKind::Npc === $of ? $this->npcs : $this->threads;

        return new PickCandidates(array_values(array_map(
            static fn (Npc|Thread $item): PickCandidate => new PickCandidate($item->id()->toString(), $item->name(), $item->weight()),
            array_filter($items, static fn (Npc|Thread $item): bool => $item->isOpen() && $item->carries($tags)),
        )));
    }

    /**
     * The current names of NPCs or Threads by id, in the order of the ids; an id the campaign does
     * not hold has none.
     *
     * @param list<string> $ids
     *
     * @return list<string>
     */
    public function itemNames(ItemKind $of, array $ids): array
    {
        $items = ItemKind::Npc === $of ? $this->npcs : $this->threads;
        $names = [];
        foreach ($ids as $id) {
            $item = array_find($items, static fn (Npc|Thread $held): bool => $held->id()->toString() === $id);
            if (null !== $item) {
                $names[] = $item->name();
            }
        }

        return $names;
    }

    /**
     * Completes the FlowRun's current step with a result of its kind.
     *
     * @throws FlowRunNotActive        when the campaign plays freely, guidance is paused or the Flow is complete
     * @throws FlowRunPositionMismatch when the current step is another
     * @throws InvalidStepResult       when the result does not complete the step
     * @throws CampaignLimitReached    when the next scene does not fit the session
     */
    public function completeFlowStep(string $stepKey, StepResult $result, GameSystemSnapshot $release, \DateTimeImmutable $at): void
    {
        $draft = clone $this;
        $draft->guided()->completeStep($stepKey, $result, $draft->flowContext($release, $at));
        $this->adopt($draft);
    }

    /**
     * Skips the FlowRun's current suggested step.
     *
     * @throws FlowRunNotActive        when the campaign plays freely, guidance is paused or the Flow is complete
     * @throws FlowRunPositionMismatch when the current step is another
     * @throws StepCannotBeSkipped     when the step is mandatory
     * @throws CampaignLimitReached    when the next scene does not fit the session
     */
    public function skipFlowStep(string $stepKey, GameSystemSnapshot $release, \DateTimeImmutable $at): void
    {
        $draft = clone $this;
        $draft->guided()->skipStep($stepKey, $draft->flowContext($release, $at));
        $this->adopt($draft);
    }

    /**
     * Ends open play in the guided scene ("End scene"): its closing starts.
     *
     * @param int $sceneNumber the guided scene, by its number in the session under way
     *
     * @throws FlowRunNotActive        when the campaign plays freely, guidance is paused or the Flow is complete
     * @throws FlowRunPositionMismatch when the FlowRun is not in open play in that scene
     * @throws CampaignLimitReached    when the next scene does not fit the session
     */
    public function endFlowScene(int $sceneNumber, GameSystemSnapshot $release, \DateTimeImmutable $at): void
    {
        $draft = clone $this;
        $draft->guided()->endScene($sceneNumber, $draft->flowContext($release, $at));
        $this->adopt($draft);
    }

    /**
     * Picks a Scene Type the FlowRun's scene pick offers; its scene starts.
     *
     * @throws FlowRunNotActive        when the campaign plays freely, guidance is paused or the Flow is complete
     * @throws FlowRunPositionMismatch when the FlowRun is not at the scene pick
     * @throws SceneTypeNotOffered     when the pick does not offer it
     * @throws NoCurrentSession        when no session is under way
     * @throws CampaignLimitReached    when the session already holds 200 scenes
     */
    public function pickSceneType(string $sceneTypeKey, GameSystemSnapshot $release, \DateTimeImmutable $at): void
    {
        $draft = clone $this;
        $draft->guided()->pickSceneType($sceneTypeKey, $draft->flowContext($release, $at));
        $this->adopt($draft);
    }

    /**
     * Picks the Scene Type of the entry rolled on the oracle table the scene pick rolls on.
     *
     * @throws FlowRunNotActive        when the campaign plays freely, guidance is paused or the Flow is complete
     * @throws FlowRunPositionMismatch when the FlowRun is not at the scene pick
     * @throws SceneTypeNotOffered     when the pick does not roll on that table, or the entry names no Scene Type
     * @throws NoCurrentSession        when no session is under way
     * @throws CampaignLimitReached    when the session already holds 200 scenes
     */
    public function pickSceneTypeByOracle(OracleTableResult $result, GameSystemSnapshot $release, \DateTimeImmutable $at): void
    {
        $draft = clone $this;
        $draft->guided()->pickSceneTypeByOracle($result, $draft->flowContext($release, $at));
        $this->adopt($draft);
    }

    /**
     * Ends the FlowRun's loop phase ("Move on"): at once at the scene pick, else after the current scene.
     *
     * @param string $phaseKey the phase to end, by its key
     *
     * @throws FlowRunNotActive        when the campaign plays freely, guidance is paused or the Flow is complete
     * @throws FlowRunPositionMismatch when the current phase is another, or the guided scene is no longer the current scene
     * @throws MoveOnNotAllowed        when the phase plays once
     * @throws CampaignLimitReached    when the next scene does not fit the session
     */
    public function moveOn(string $phaseKey, GameSystemSnapshot $release, \DateTimeImmutable $at): void
    {
        $draft = clone $this;
        $draft->guided()->moveOn($phaseKey, $draft->flowContext($release, $at));
        $this->adopt($draft);
    }

    /**
     * Pauses guidance: the player plays freely until it resumes.
     *
     * @throws FlowRunNotActive when the campaign plays freely, guidance is already paused or the Flow is complete
     */
    public function pauseGuidance(\DateTimeImmutable $at): void
    {
        $draft = clone $this;
        $draft->guided()->pause($at);
        $this->adopt($draft);
    }

    /**
     * Resumes guidance where it stopped; a guided scene that is no longer the current one is
     * abandoned and the FlowRun goes on at the scene pick.
     *
     * @throws FlowRunNotActive     when the campaign plays freely, guidance is not paused or the Flow is complete
     * @throws CampaignLimitReached when the next scene does not fit the session
     */
    public function resumeGuidance(GameSystemSnapshot $release, \DateTimeImmutable $at): void
    {
        $draft = clone $this;
        $draft->guided()->resume($draft->flowContext($release, $at));
        $this->adopt($draft);
    }

    /**
     * The campaign's guidance along its Flow; null when played freely. It is a copy: change the
     * FlowRun only through the Campaign commands, which keep the campaign consistent.
     */
    public function flowRun(): ?FlowRun
    {
        return $this->flowRun instanceof FlowRun ? clone $this->flowRun : null;
    }

    /**
     * What the player sees of the FlowRun; null when played freely.
     *
     * @throws UnknownFlow when the pinned release has no Flow with the campaign's key
     */
    public function flowRunView(GameSystemSnapshot $release): ?FlowRunView
    {
        return $this->flowRun?->view($release, $this->playedFlow($release), $this->currentSession()?->number(), $this->trackerValuesOf($release), $this->factValues($release), $this->pickedNames(), fn (PickStep $step): PickCandidates => $this->pickCandidates($step->of, $step->tags));
    }

    /**
     * The current names of the items "{picked}" stands for, in pick order.
     *
     * @return list<string>
     */
    private function pickedNames(): array
    {
        $picked = $this->flowRun?->picked();

        return $picked instanceof PickedItems ? $this->itemNames($picked->kind, $picked->ids) : [];
    }

    /**
     * The campaign's value of every Tracker of the pinned release, by key (see trackerValue()).
     *
     * @return array<string, int>
     */
    public function trackerValuesOf(GameSystemSnapshot $release): array
    {
        $values = [];
        foreach ($release->trackers() as $tracker) {
            $values[$tracker->key] = $this->trackerValue($tracker);
        }

        return $values;
    }

    /**
     * What the player sees of the FlowRun, which must be guiding: the view a FlowRun command acts on.
     *
     * @throws FlowRunNotActive when the campaign plays freely, guidance is paused or the Flow is complete
     * @throws UnknownFlow      when the pinned release has no Flow with the campaign's key
     */
    public function activeFlowRunView(GameSystemSnapshot $release): FlowRunView
    {
        $view = $this->flowRunView($release) ?? throw FlowRunNotActive::none();

        return FlowRunStatus::Active === $view->status ? $view : throw FlowRunNotActive::toPlay($view->status);
    }

    public function __clone()
    {
        if ($this->flowRun instanceof FlowRun) {
            $this->flowRun = clone $this->flowRun;
        }
    }

    /**
     * @throws FlowRunNotActive
     */
    private function guided(): FlowRun
    {
        return $this->flowRun ?? throw FlowRunNotActive::none();
    }

    /**
     * Takes the state of a copy that ran a FlowRun command to its end, the copy's FlowRun included. A
     * command runs on a copy so that it changes the campaign all or nothing: when it fails, e.g. on a
     * full session while starting the next scene, the copy is dropped.
     */
    private function adopt(self $draft): void
    {
        [$this->sessions, $this->trackerValues, $this->flowRun, $this->npcs, $this->threads, $this->facts] = [$draft->sessions, $draft->trackerValues, $draft->flowRun, $draft->npcs, $draft->threads, $draft->facts];
    }

    /**
     * The FlowRun about to change, replaced by a copy first (see the class comment); null when played freely.
     */
    private function changedFlowRun(): ?FlowRun
    {
        if ($this->flowRun instanceof FlowRun) {
            $this->flowRun = clone $this->flowRun;
        }

        return $this->flowRun;
    }

    private function flowContext(GameSystemSnapshot $release, \DateTimeImmutable $at): FlowRunContext
    {
        return new FlowRunContext($this, $release, $this->playedFlow($release), $at);
    }

    /**
     * @throws UnknownFlow when the pinned release has no Flow with the campaign's key
     */
    private function playedFlow(GameSystemSnapshot $release): Flow
    {
        $this->assertPinned($release);

        return $release->flow((string) $this->flowKey) ?? throw UnknownFlow::withKey((string) $this->flowKey);
    }

    /**
     * @throws \LogicException when the snapshot is another release than the pinned one
     */
    private function assertPinned(GameSystemSnapshot $release): void
    {
        if ($release->gameSystemKey() !== $this->gameSystemKey || $release->releaseVersion() !== $this->releaseVersion) {
            throw new \LogicException('The campaign works with its pinned release.');
        }
    }

    /**
     * @throws UnknownCharacter
     */
    private function characterIndex(CharacterId $id): int
    {
        return $this->characterIndexOrNull($id) ?? throw UnknownCharacter::withId($id);
    }

    private function characterIndexOrNull(CharacterId $id): ?int
    {
        foreach ($this->characters as $index => $character) {
            if ($character->id()->equals($id)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @throws UnknownNpc
     */
    private function npcIndex(NpcId $id): int
    {
        return $this->npcIndexOrNull($id) ?? throw UnknownNpc::withId($id);
    }

    private function npcIndexOrNull(NpcId $id): ?int
    {
        foreach ($this->npcs as $index => $npc) {
            if ($npc->id()->equals($id)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @throws UnknownThread
     */
    private function threadIndex(ThreadId $id): int
    {
        return $this->threadIndexOrNull($id) ?? throw UnknownThread::withId($id);
    }

    private function threadIndexOrNull(ThreadId $id): ?int
    {
        foreach ($this->threads as $index => $thread) {
            if ($thread->id()->equals($id)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @throws FactAlreadyExists    when a fact holds the id
     * @throws CampaignLimitReached when the campaign already holds 500 facts
     * @throws UnknownNpc           when a link names an NPC the campaign does not hold
     * @throws UnknownThread        when a link names a Thread the campaign does not hold
     * @throws UnknownFact          when a link names a fact the campaign does not hold
     */
    private function newFact(FactId $id, Fact $fact): Fact
    {
        if (\count($this->facts) >= self::MAX_FACTS) {
            throw CampaignLimitReached::facts(self::MAX_FACTS);
        }

        if ($this->fact($id) instanceof Fact) {
            throw FactAlreadyExists::withId($id);
        }

        foreach ($fact->links() as $link) {
            $this->assertLinkResolves($link);
        }

        return $fact;
    }

    /**
     * The facts hold together: at most 500, distinct ids, one fact per slot, and every link and
     * subject names an NPC, Thread or fact of this campaign. Called by reconstitute() and, for a
     * stored row, after Doctrine loads it (the facts column cannot see the NPCs and Threads).
     *
     * @throws FactAlreadyExists    when two facts share an id
     * @throws CampaignLimitReached when there are more than 500 facts
     * @throws InvalidFact          when two facts fill one slot
     * @throws UnknownNpc           when a link or subject names an NPC the campaign does not hold
     * @throws UnknownThread        when a link or subject names a Thread the campaign does not hold
     * @throws UnknownFact          when a link names a fact the campaign does not hold
     */
    public function assertFactsConsistent(): void
    {
        if (\count($this->facts) > self::MAX_FACTS) {
            throw CampaignLimitReached::facts(self::MAX_FACTS);
        }

        $ids = [];
        $slots = [];
        foreach ($this->facts as $fact) {
            $key = $fact->id()->toString();
            if (isset($ids[$key])) {
                throw FactAlreadyExists::withId($fact->id());
            }

            $ids[$key] = true;
            if (null !== $fact->slot()) {
                if (isset($slots[$fact->slot()])) {
                    throw InvalidFact::because(\sprintf('Slot "%s" holds two facts.', $fact->slot()));
                }

                $slots[$fact->slot()] = true;
            }
        }

        foreach ($this->facts as $fact) {
            foreach ([...$fact->links(), ...(null === $fact->subject() ? [] : [$fact->subject()])] as $link) {
                $this->assertLinkResolves($link);
            }
        }
    }

    /**
     * @throws UnknownNpc
     * @throws UnknownThread
     * @throws UnknownFact
     */
    private function assertLinkResolves(FactLink $link): void
    {
        match ($link->kind) {
            FactLinkKind::Npc => $this->npcIndex(NpcId::fromString($link->id)),
            FactLinkKind::Thread => $this->threadIndex(ThreadId::fromString($link->id)),
            FactLinkKind::Fact => $this->factIndex(FactId::fromString($link->id)),
        };
    }

    /**
     * @throws UnknownFact
     */
    private function factIndex(FactId $id): int
    {
        return $this->factIndexOrNull($id) ?? throw UnknownFact::withId($id);
    }

    private function factIndexOrNull(FactId $id): ?int
    {
        foreach ($this->facts as $index => $fact) {
            if ($fact->id()->equals($id)) {
                return $index;
            }
        }

        return null;
    }

    private function factIndexOfSlot(string $slot): ?int
    {
        foreach ($this->facts as $index => $fact) {
            if ($fact->slot() === $slot) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @template T of Npc|Thread|Fact|Character
     *
     * @param list<T> $items
     * @param T       $item
     *
     * @return list<T> a copy with the item at the index replaced, so that the stored list is seen to change
     */
    private function replaced(array $items, int $index, Npc|Thread|Fact|Character $item): array
    {
        $items[$index] = $item;

        return array_values($items);
    }

    /**
     * @template T of Npc|Thread
     *
     * @param list<T> $items
     *
     * @return list<T>
     */
    private function openFirst(array $items): array
    {
        return [
            ...array_filter($items, static fn (Npc|Thread $item): bool => $item->isOpen()),
            ...array_filter($items, static fn (Npc|Thread $item): bool => !$item->isOpen()),
        ];
    }

    /**
     * @param \Closure(Session): Session $add adds the scene to the current session
     *
     * @throws NoCurrentSession
     * @throws CampaignLimitReached
     * @throws InvalidSceneTitle
     */
    private function addScene(\Closure $add): Scene
    {
        $session = $add($this->currentSession() ?? throw NoCurrentSession::toStartScene());
        $this->sessions = [...\array_slice($this->sessions, 0, -1), $session];

        return $session->currentScene() ?? throw new \LogicException('A scene was just added.');
    }

    /**
     * The Scene Type name numbered after the campaign's scenes of that type, cut so that the number
     * fits the longest title.
     */
    private function defaultSceneTitle(SceneType $sceneType): string
    {
        $count = 0;
        foreach ($this->sessions as $session) {
            foreach ($session->scenes() as $scene) {
                if ($scene->sceneType() === $sceneType->key) {
                    ++$count;
                }
            }
        }

        $number = ' '.($count + 1);

        return mb_substr($sceneType->name, 0, Scene::MAX_TITLE_LENGTH - \strlen($number)).$number;
    }

    /**
     * How many times the stored campaign had been saved when this copy was loaded (1 once added),
     * as kept by the persistence adapter. Rules never depend on it.
     */
    public function version(): int
    {
        return $this->version;
    }

    public function id(): CampaignId
    {
        return CampaignId::fromString($this->id);
    }

    public function ownerId(): string
    {
        return $this->ownerId;
    }

    public function isOwnedBy(string $userId): bool
    {
        return $this->ownerId === $userId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function pinnedRelease(): PinnedRelease
    {
        return PinnedRelease::of($this->gameSystemKey, $this->releaseVersion, $this->gameSystemName);
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * The key of the Flow of the pinned release the campaign plays along; null when played freely.
     */
    public function flowKey(): ?string
    {
        return $this->flowKey;
    }

    /**
     * @return list<Session> in number order
     */
    public function sessions(): array
    {
        return $this->sessions;
    }

    /**
     * The session under way: the latest one unless it has ended; null before the first one starts.
     */
    public function currentSession(): ?Session
    {
        $latest = array_last($this->sessions);

        return true === $latest?->hasEnded() ? null : $latest;
    }

    /**
     * The latest scene of the current session, or null when there is none.
     */
    public function currentScene(): ?Scene
    {
        return $this->currentSession()?->currentScene();
    }
}
