<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

use App\Play\Domain\Campaign\Characters\Character;
use App\Play\Domain\Campaign\Characters\CharacterAlreadyExists;
use App\Play\Domain\Campaign\Characters\CharacterCreation;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\CharacterValues;
use App\Play\Domain\Campaign\Characters\CreationMethod;
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
use App\Play\Domain\Campaign\FlowRun\CheckStepPending;
use App\Play\Domain\Campaign\FlowRun\FlowFormulaFailed;
use App\Play\Domain\Campaign\FlowRun\FlowNote;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunContext;
use App\Play\Domain\Campaign\FlowRun\FlowRunNotActive;
use App\Play\Domain\Campaign\FlowRun\FlowRunPositionMismatch;
use App\Play\Domain\Campaign\FlowRun\FlowRunStatus;
use App\Play\Domain\Campaign\FlowRun\FlowRunView;
use App\Play\Domain\Campaign\FlowRun\InvalidStepResult;
use App\Play\Domain\Campaign\FlowRun\MoveOnNotAllowed;
use App\Play\Domain\Campaign\FlowRun\NoPendingCheck;
use App\Play\Domain\Campaign\FlowRun\PendingCheck;
use App\Play\Domain\Campaign\FlowRun\PickCandidate;
use App\Play\Domain\Campaign\FlowRun\PickCandidates;
use App\Play\Domain\Campaign\FlowRun\PickedItem;
use App\Play\Domain\Campaign\FlowRun\PickedItems;
use App\Play\Domain\Campaign\FlowRun\SceneCannotStartWhileGuided;
use App\Play\Domain\Campaign\FlowRun\SceneTypeNotOffered;
use App\Play\Domain\Campaign\FlowRun\SessionCannotEndWhileGuided;
use App\Play\Domain\Campaign\FlowRun\SheetChange;
use App\Play\Domain\Campaign\FlowRun\StepCannotBeSkipped;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\Campaign\Lists\GeneratedItemId;
use App\Play\Domain\Campaign\Lists\InvalidNpc;
use App\Play\Domain\Campaign\Lists\InvalidPlace;
use App\Play\Domain\Campaign\Lists\InvalidPromotion;
use App\Play\Domain\Campaign\Lists\InvalidThread;
use App\Play\Domain\Campaign\Lists\ItemRules;
use App\Play\Domain\Campaign\Lists\Npc;
use App\Play\Domain\Campaign\Lists\NpcAlreadyExists;
use App\Play\Domain\Campaign\Lists\NpcAlreadyPromoted;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\Place;
use App\Play\Domain\Campaign\Lists\PlaceAlreadyExists;
use App\Play\Domain\Campaign\Lists\PlaceId;
use App\Play\Domain\Campaign\Lists\TagNotApplicable;
use App\Play\Domain\Campaign\Lists\Thread;
use App\Play\Domain\Campaign\Lists\ThreadAlreadyExists;
use App\Play\Domain\Campaign\Lists\ThreadId;
use App\Play\Domain\Campaign\Lists\UnknownNpc;
use App\Play\Domain\Campaign\Lists\UnknownPlace;
use App\Play\Domain\Campaign\Lists\UnknownReleaseTag;
use App\Play\Domain\Campaign\Lists\UnknownThread;
use App\Play\Domain\Campaign\Transfer\TransferFromOutdatedRelease;
use App\Play\Domain\Campaign\Transfer\TransferToAnotherGameSystem;
use App\Play\Domain\Campaign\Upgrade\CampaignUpToDate;
use App\Play\Domain\Campaign\Upgrade\ReleaseUpgradeChanged;
use App\Play\Domain\Campaign\Upgrade\ReleaseUpgradePlan;
use App\Play\Domain\Campaign\Upgrade\ReleaseUpgradePlanner;
use App\Play\Domain\Campaign\Upgrade\UpgradeToAnotherGameSystem;
use App\Play\Domain\GameSystem\Check\SpecialResult;
use App\Play\Domain\GameSystem\FactSlot;
use App\Play\Domain\GameSystem\Flow\Flow;
use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Play\Domain\GameSystem\Flow\PickStep;
use App\Play\Domain\GameSystem\Flow\TrackerOperation;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\SceneType;
use App\Play\Domain\GameSystem\SnapshotLikelihoodOracle;
use App\Play\Domain\GameSystem\Tag;
use App\Play\Domain\GameSystem\Tracker;
use App\Play\Domain\GameSystem\UnknownFlow;
use App\Randomness\Domain\Oracle\OracleTableResult;
use App\Randomness\Domain\RandomNumberGenerator;

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
 * It also keeps the player's NPCs, Threads and Places (at most 500 of each), added, edited, closed and
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

    public const int MAX_PLACES = 500;

    public const int MAX_FACTS = 500;

    public const int MAX_CHARACTERS = 100;

    /**
     * Concurrency token, owned by the persistence adapter: it counts saves so that a save of a
     * stale copy is refused (CampaignModifiedConcurrently). The model never changes it.
     */
    private int $version = 1;

    private ?FlowRun $flowRun = null;

    /**
     * What the Flow decided on its own during the FlowRun command that last ran, in apply order. It is
     * transient: never stored, handed out once by takeFlowNotes(), and only kept from a command that
     * succeeded (a command runs on a copy that is dropped when it fails).
     *
     * @var list<FlowNote>
     */
    private array $flowNotes = [];

    /**
     * @param list<Session>      $sessions      in number order
     * @param array<string, int> $trackerValues by Tracker key
     * @param list<Npc>          $npcs          in creation order
     * @param list<Thread>       $threads       in creation order
     * @param list<Fact>         $facts         in creation order
     * @param list<Character>    $characters    in creation order
     * @param list<Place>        $places        in creation order
     */
    private function __construct(
        private readonly string $id,
        private readonly string $ownerId,
        private readonly string $name,
        private string $gameSystemKey,
        private int $releaseVersion,
        private string $gameSystemName,
        private readonly \DateTimeImmutable $createdAt,
        private array $sessions,
        private array $trackerValues,
        private ?string $flowKey,
        private array $npcs = [],
        private array $threads = [],
        private array $facts = [],
        private array $characters = [],
        private array $places = [],
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
     * Starts a new campaign from this one (ADR 0046), on the latest release of the same GameSystem,
     * which this campaign plays: it carries every NPC, Thread and Campaign fact and the chosen
     * Characters, all with their ids, and starts the rest as Campaign::create() does (Trackers at
     * their initial value, a fresh FlowRun when it plays a Flow, no sessions). An NPC whose
     * Character stays behind keeps its data and loses the promotion. This campaign is not changed.
     *
     * @param GameSystemSnapshot $latest       the latest release of the GameSystem; this campaign must play it
     * @param ?string            $flowKey      a Flow of that release to play along, or null to play freely
     * @param list<CharacterId>  $characterIds the Characters to carry, in any order; the new campaign keeps this campaign's order
     *
     * @throws TransferToAnotherGameSystem when the release belongs to another GameSystem
     * @throws TransferFromOutdatedRelease when this campaign does not play the release
     * @throws UnknownFlow                 when the release has no Flow with the key
     * @throws UnknownCharacter            when this campaign holds no Character with an id
     * @throws CharacterAlreadyExists      when an id is given twice
     * @throws InvalidCampaignName         when the trimmed name is blank or longer than 100 characters
     */
    public function transfer(CampaignId $id, string $name, GameSystemSnapshot $latest, ?string $flowKey, array $characterIds, \DateTimeImmutable $createdAt): self
    {
        if ($latest->gameSystemKey() !== $this->gameSystemKey) {
            throw TransferToAnotherGameSystem::of($this->gameSystemKey, $latest->gameSystemKey());
        }

        if ($latest->releaseVersion() !== $this->releaseVersion) {
            throw TransferFromOutdatedRelease::of($this->releaseVersion, $latest->releaseVersion());
        }

        $flow = null === $flowKey ? null : ($latest->flow($flowKey) ?? throw UnknownFlow::withKey($flowKey));
        $carried = [];
        foreach ($characterIds as $characterId) {
            if (isset($carried[$characterId->toString()])) {
                throw CharacterAlreadyExists::withId($characterId);
            }

            if (!$this->character($characterId) instanceof Character) {
                throw UnknownCharacter::withId($characterId);
            }

            $carried[$characterId->toString()] = true;
        }

        $new = self::create($id, $this->ownerId, $name, $this->pinnedRelease(), $createdAt, $latest->trackers(), $flow);
        $new->characters = array_values(array_filter($this->characters, static fn (Character $character): bool => isset($carried[$character->id()->toString()])));
        $new->npcs = array_map(static fn (Npc $npc): Npc => $npc->promotedTo() instanceof CharacterId && !isset($carried[$npc->promotedTo()->toString()]) ? $npc->withoutPromotion() : $npc, $this->npcs);
        $new->threads = $this->threads;
        $new->facts = $this->facts;
        $new->assertFactsConsistent();

        return $new;
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
     * @param list<Place>        $places        in creation order
     *
     * @throws NpcAlreadyExists       when two NPCs share an id
     * @throws CharacterAlreadyExists when two Characters share an id
     * @throws ThreadAlreadyExists    when two Threads share an id
     * @throws PlaceAlreadyExists     when two Places share an id
     * @throws FactAlreadyExists      when two facts share an id
     * @throws CampaignLimitReached   when there are more than 500 facts
     * @throws InvalidFact            when two facts fill one slot, or a fact links to itself
     * @throws UnknownNpc             when a link or subject names an NPC the campaign does not hold
     * @throws UnknownThread          when a link or subject names a Thread the campaign does not hold
     * @throws UnknownPlace           when a link or subject names a Place the campaign does not hold
     * @throws UnknownFact            when a link names a fact the campaign does not hold
     * @throws InvalidPromotion       when a promotion is named by one side only
     */
    public static function reconstitute(CampaignId $id, string $ownerId, string $name, PinnedRelease $pinnedRelease, \DateTimeImmutable $createdAt, array $sessions, array $trackerValues = [], ?string $flowKey = null, ?FlowRun $flowRun = null, array $npcs = [], array $threads = [], array $facts = [], array $characters = [], array $places = []): self
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

        $placeIds = [];
        foreach ($places as $place) {
            $key = $place->id()->toString();
            if (isset($placeIds[$key])) {
                throw PlaceAlreadyExists::withId($place->id());
            }

            $placeIds[$key] = true;
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
            $places,
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
     * Starts session n + 1 (the first is 1). It becomes the current session, with no scene yet and
     * the previous session's party (none for the first). Only one session is open at a time: the one
     * under way has to end first. A guided FlowRun goes on in it: its session opening
     * runs (in a hook Scene), then a scene pick with one Scene Type starts that scene; a guided
     * scene or hook Scene of an earlier session is abandoned. All or nothing, like the FlowRun
     * commands.
     *
     * @param ?GameSystemSnapshot    $release the pinned release; without it a FlowRun is not told
     * @param ?RandomNumberGenerator $random  the source a shop step reached on the way draws its stock with (ADR 0045)
     *
     * @throws SessionAlreadyUnderWay when a session is under way
     * @throws CampaignLimitReached   when the campaign already holds 500 sessions
     */
    public function startSession(\DateTimeImmutable $startedAt, ?GameSystemSnapshot $release = null, ?RandomNumberGenerator $random = null): Session
    {
        $underWay = $this->currentSession();
        if ($underWay instanceof Session) {
            throw SessionAlreadyUnderWay::numbered($underWay->number());
        }

        if (\count($this->sessions) >= self::MAX_SESSIONS) {
            throw CampaignLimitReached::sessions(self::MAX_SESSIONS);
        }

        $session = Session::start(\count($this->sessions) + 1, $startedAt, array_last($this->sessions)?->party() ?? []);
        $draft = clone $this;
        $draft->sessions[] = $session;
        if ($release instanceof GameSystemSnapshot) {
            $draft->changedFlowRun()?->sessionStarted($draft->flowContext($release, $startedAt, $random));
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
     * @param ?int                   $number  the number of the session the caller means to end, or null for whichever is under way
     * @param ?GameSystemSnapshot    $release the pinned release; without it a FlowRun is not told and the session ends at once
     * @param ?RandomNumberGenerator $random  the source a shop step reached on the way draws its stock with (ADR 0045)
     *
     * @return Session the session, ended unless its session closing runs first
     *
     * @throws NoCurrentSession            when no session is under way (none has started, or it has
     *                                     ended), or another session than the named one is
     * @throws SessionCannotEndWhileGuided when guidance is active and the FlowRun is in a scene or a hook
     * @throws CampaignLimitReached        when the session closing's hook Scene does not fit the session
     * @throws CheckStepPending            when a check step's roll waits for Keep or Push
     */
    public function endSession(\DateTimeImmutable $endedAt, ?int $number = null, ?GameSystemSnapshot $release = null, ?RandomNumberGenerator $random = null): Session
    {
        $session = $this->currentSession() ?? throw NoCurrentSession::toEndSession();
        if (null !== $number && $session->number() !== $number) {
            throw NoCurrentSession::numbered($number);
        }

        if ($release instanceof GameSystemSnapshot && FlowRunStatus::Active === $this->flowRun?->status()) {
            $draft = clone $this;
            $draft->guided()->endSession($draft->flowContext($release, $endedAt, $random));
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
     * This is the player's own way to start a scene: it is refused while the Flow guides (a FlowRun
     * not paused and not complete), which starts its scenes itself (see startGuidedScene()).
     *
     * @param ?SceneType $sceneType a Scene Type of the pinned release, or null
     *
     * @throws SceneCannotStartWhileGuided when the Flow guides the campaign
     * @throws NoCurrentSession            when no session is under way
     * @throws CampaignLimitReached        when the current session already holds 200 scenes
     * @throws InvalidSceneTitle           when the trimmed title is blank or longer than 100 characters, or
     *                                     there is neither a title nor a Scene Type
     */
    public function startScene(?string $title, \DateTimeImmutable $startedAt, ?SceneType $sceneType = null): Scene
    {
        if (FlowRunStatus::Active === $this->flowRun?->status()) {
            throw SceneCannotStartWhileGuided::byHand();
        }

        return $this->addPlayScene($title, $startedAt, $sceneType);
    }

    /**
     * Starts a scene of play for the FlowRun, which guides the campaign: the Scene Type with its
     * default title.
     *
     * @throws NoCurrentSession     when no session is under way
     * @throws CampaignLimitReached when the current session already holds 200 scenes
     */
    public function startGuidedScene(SceneType $sceneType, \DateTimeImmutable $startedAt): Scene
    {
        return $this->addPlayScene(null, $startedAt, $sceneType);
    }

    /**
     * @throws NoCurrentSession
     * @throws CampaignLimitReached
     * @throws InvalidSceneTitle
     */
    private function addPlayScene(?string $title, \DateTimeImmutable $startedAt, ?SceneType $sceneType): Scene
    {
        $isDefault = null === $title;
        $title ??= $sceneType instanceof SceneType ? $this->defaultSceneTitle($sceneType) : throw InvalidSceneTitle::missing();

        return $this->addScene(static fn (Session $session): Session => $session->withNewScene($title, $startedAt, $sceneType?->key, $isDefault));
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
     * Switches the current scene to another Scene Type by hand (free play). Its number and journal
     * entries stay. A title that is still the old Scene Type's default ("Legwork 2", as the scene
     * records; a scene stored without the record is judged by the title's shape) becomes the
     * new type's default, numbered as for a new scene of that type ("Firefight 1"); a title the
     * player or an Effect gave stays. With a FlowRun the switch is in its history, and while
     * guidance is active a guided scene goes on at the new type's setup (a sceneTitle Effect
     * there wins over the new default).
     *
     * @param SceneType              $sceneType a Scene Type of the pinned release
     * @param ?GameSystemSnapshot    $release   the pinned release, which names the old Scene Type, with
     *                                          the time of the switch; without them a default title
     *                                          stays and a FlowRun is not told (see startSession())
     * @param ?RandomNumberGenerator $random    the source a shop step reached on the way draws its stock with (ADR 0045)
     *
     * @throws NoCurrentScene          when the current session has no scene, or no session is under way
     * @throws HookSceneHasNoSceneType when the current scene is a hook Scene
     * @throws CheckStepPending        when a check step's roll waits for Keep or Push
     */
    public function switchSceneType(SceneType $sceneType, ?GameSystemSnapshot $release = null, ?\DateTimeImmutable $at = null, ?RandomNumberGenerator $random = null): Scene
    {
        // A roll waiting for Keep or Push belongs to the scene as it is.
        $this->flowRun?->assertNoPendingCheck();
        $session = $this->currentSession();
        $scene = $session?->currentScene() ?? throw NoCurrentScene::toSwitchSceneType();
        $switched = $scene->withSceneType($sceneType->key);
        $previous = null === $scene->sceneType() ? null : $release?->sceneType($scene->sceneType());
        if ($previous instanceof SceneType && $previous->key !== $sceneType->key && ($scene->hasDefaultTitle() ?? $this->isDefaultSceneTitle($scene->title(), $previous))) {
            // The scene still has the old type here, so the count of the new type leaves it out.
            $switched = $switched->withDefaultTitle($this->defaultSceneTitle($sceneType));
        }

        $this->sessions = [...\array_slice($this->sessions, 0, -1), $session->withCurrentScene($switched)];
        if ($release instanceof GameSystemSnapshot && $at instanceof \DateTimeImmutable) {
            $this->changedFlowRun()?->followSceneTypeSwitch($scene->sceneType(), $sceneType, $this->flowContext($release, $at, $random));
        }

        return $switched;
    }

    /**
     * Renames the current scene (a sceneTitle Effect of the FlowRun, or the player).
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
     * included). The Characters part changes only when it is given. All or nothing.
     *
     * @param list<NpcId>        $cast       the NPCs in the scene
     * @param ?list<CharacterId> $characters the Characters in the scene, or null to keep the ones it has
     *
     * @throws NoCurrentScene   when the current session has no scene, or no session is under way
     * @throws UnknownNpc       when an id is not an NPC of the campaign
     * @throws UnknownCharacter when an id is not a Character of the campaign
     * @throws InvalidSceneCast when an NPC or a Character is listed twice
     */
    public function editCurrentSceneCast(array $cast, ?array $characters = null): Scene
    {
        $session = $this->currentSession();
        $scene = $session?->currentScene() ?? throw NoCurrentScene::toEditCast();
        foreach ($cast as $id) {
            if (!$this->npc($id) instanceof Npc) {
                throw UnknownNpc::withId($id);
            }
        }

        foreach ($characters ?? [] as $id) {
            $this->characterIndex($id);
        }

        $edited = $scene->withCast($cast);
        if (null !== $characters) {
            $edited = $edited->withCharacters($characters);
        }

        $this->sessions = [...\array_slice($this->sessions, 0, -1), $session->withCurrentScene($edited)];

        return $edited;
    }

    /**
     * Replaces the current session's party (any Characters of the campaign, in this order). The
     * scenes, the current one included, keep their Characters. All or nothing.
     *
     * @param list<CharacterId> $party
     *
     * @throws NoCurrentSession    when no session is under way (none has started, or it has ended)
     * @throws UnknownCharacter    when an id is not a Character of the campaign
     * @throws InvalidSessionParty when a Character is listed twice
     */
    public function editCurrentSessionParty(array $party): Session
    {
        $session = $this->currentSession() ?? throw NoCurrentSession::toEditParty();
        foreach ($party as $id) {
            $this->characterIndex($id);
        }

        $edited = $session->withParty($party);
        $this->sessions = [...\array_slice($this->sessions, 0, -1), $edited];

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
     * Adds Characters to the end of the current scene's Characters, those already in it staying
     * where they are; with $toParty also to the end of the current session's party (a pick step
     * of the FlowRun in a hook, or a character step). All or nothing.
     *
     * @param list<CharacterId> $ids Characters of the campaign
     *
     * @throws NoCurrentScene   when the current session has no scene, or no session is under way
     * @throws UnknownCharacter when an id is not a Character of the campaign
     */
    public function addToCurrentSceneCharacters(array $ids, bool $toParty): void
    {
        $session = $this->currentSession();
        $scene = $session?->currentScene() ?? throw NoCurrentScene::toEditCast();
        foreach ($ids as $id) {
            $this->characterIndex($id);
        }

        $session = $toParty ? $session->withParty($this->withIds($session->party(), $ids)) : $session;
        $this->sessions = [...\array_slice($this->sessions, 0, -1), $session->withCurrentScene($scene->withCharacters($this->withIds($scene->characters(), $ids)))];
    }

    /**
     * @param list<CharacterId> $held
     * @param list<CharacterId> $ids
     *
     * @return list<CharacterId> the held ones, then the ids not held yet
     */
    private function withIds(array $held, array $ids): array
    {
        foreach ($ids as $id) {
            if (!array_any($held, static fn (CharacterId $member): bool => $member->equals($id))) {
                $held[] = $id;
            }
        }

        return $held;
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
     * @throws TagNotApplicable     when a Tag is limited to other kinds than the entity's
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
        ItemRules::assertInRelease($npc->tags(), $release, ItemKind::Npc);
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
     * @throws TagNotApplicable  when a Tag is limited to other kinds than the entity's
     */
    public function editNpc(NpcId $id, string $name, ?string $agenda, array $tags, array $freeTags, int $weight, GameSystemSnapshot $release): Npc
    {
        $this->assertPinned($release);
        $index = $this->npcIndex($id);
        $edited = $this->npcs[$index]->edited($name, $agenda, $tags, $freeTags, $weight);
        ItemRules::assertInRelease($edited->tags(), $release, ItemKind::Npc);
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
     * @throws UnknownNpc         when the campaign holds no Npc with this id
     * @throws NpcAlreadyPromoted when the Npc was promoted to a Character, which closes it for good
     */
    public function reopenNpc(NpcId $id): void
    {
        $index = $this->npcIndex($id);
        if (!$this->npcs[$index]->isOpen()) {
            $this->npcs = $this->replaced($this->npcs, $index, $this->npcs[$index]->reopened());
        }
    }

    /**
     * Promotes the Npc to a new Character: its name, Tags and free tags, the default of every
     * field of the pinned release's sheet (a dice default is rolled, a start max evaluated, as when
     * a player creates by roll), and the Npc recorded as its origin. The Npc, open or
     * closed, ends closed and records the Character. While a session is under way the Character
     * joins its party and the current scene's cast, where it takes the Npc's place. All or nothing.
     *
     * @param ?RandomNumberGenerator $random the source the sheet's dice defaults roll with
     *
     * @throws UnknownNpc             when the campaign holds no Npc with this id
     * @throws InvalidCharacter       when the sheet's start max cannot be computed
     * @throws NpcAlreadyPromoted     when the Npc was promoted already
     * @throws CampaignLimitReached   when the campaign already holds 100 Characters
     * @throws CharacterAlreadyExists when the campaign holds a Character with this id
     * @throws UnknownReleaseTag      when a Tag key is not in the pinned release
     * @throws TagNotApplicable       when a Tag is limited to other kinds than the entity's
     */
    public function promoteNpc(NpcId $npcId, CharacterId $characterId, GameSystemSnapshot $release, ?RandomNumberGenerator $random = null): Character
    {
        $this->assertPinned($release);
        $index = $this->npcIndex($npcId);
        $promoted = $this->npcs[$index]->promoted($characterId);
        if (\count($this->characters) >= self::MAX_CHARACTERS) {
            throw CampaignLimitReached::characters(self::MAX_CHARACTERS);
        }

        if ($this->character($characterId) instanceof Character) {
            throw CharacterAlreadyExists::withId($characterId);
        }

        $npc = $this->npcs[$index];
        // A Tag limited to NPCs stays on the NPC: the Character takes the Tags that apply to Characters.
        $tags = array_values(array_filter($npc->tags(), static fn (string $key): bool => !($tag = $release->tag($key)) instanceof Tag || $tag->isFor(ItemKind::Character)));
        $character = Character::of($characterId, $npc->name(), $tags, $npc->freeTags(), CharacterCreation::promoted($release->sheet(), $random), $npcId);
        ItemRules::assertInRelease($character->tags(), $release, ItemKind::Character);
        $this->npcs = $this->replaced($this->npcs, $index, $promoted);
        $this->characters = [...$this->characters, $character];
        $this->joinCurrentSession($characterId, $npcId);

        return $character;
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
     * Character stores one value per field of the pinned release's sheet. While a session is under
     * way the Character joins its party and the current scene's cast. The sheet may have the server
     * roll fields and start tracks full from a formula, and have the player spend a point budget
     * ($method, ADR 0040).
     *
     * @param list<string>           $tags     release Tag keys of the pinned release
     * @param list<string>           $freeTags
     * @param array<mixed>           $values   field key to value
     * @param ?RandomNumberGenerator $random   the source the sheet's dice defaults roll with
     *
     * @throws InvalidCharacter       when a value breaks a rule or does not fit the sheet, or the method does not fit it
     * @throws UnknownReleaseTag      when a Tag key is not in the pinned release
     * @throws TagNotApplicable       when a Tag is limited to other kinds than the entity's
     * @throws CharacterAlreadyExists when the campaign holds a Character with this id
     * @throws CampaignLimitReached   when the campaign already holds 100 Characters
     */
    public function addCharacter(CharacterId $id, string $name, array $tags, array $freeTags, array $values, GameSystemSnapshot $release, ?CreationMethod $method = null, ?RandomNumberGenerator $random = null): Character
    {
        $this->assertPinned($release);
        if (\count($this->characters) >= self::MAX_CHARACTERS) {
            throw CampaignLimitReached::characters(self::MAX_CHARACTERS);
        }

        if ($this->character($id) instanceof Character) {
            throw CharacterAlreadyExists::withId($id);
        }

        $character = Character::of($id, $name, $tags, $freeTags, CharacterCreation::values($release->sheet(), $values, $method, $random));
        ItemRules::assertInRelease($character->tags(), $release, ItemKind::Character);
        $this->characters = [...$this->characters, $character];
        $this->joinCurrentSession($id);

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
     * @throws TagNotApplicable  when a Tag is limited to other kinds than the entity's
     */
    public function editCharacter(CharacterId $id, string $name, array $tags, array $freeTags, array $values, GameSystemSnapshot $release): Character
    {
        $this->assertPinned($release);
        $index = $this->characterIndex($id);
        $edited = $this->characters[$index]->edited($name, $tags, $freeTags, CharacterValues::resolve($release->sheet(), $values));
        ItemRules::assertInRelease($edited->tags(), $release, ItemKind::Character);
        $this->characters = $this->replaced($this->characters, $index, $edited);

        return $edited;
    }

    /**
     * Sets one stored value of a Character, as a sheet Effect changes it (FlowRunContext clamps it to
     * the field first); the rest of the Character stays.
     *
     * @param int|list<string>|list<array<string, int|string>>|array{current: int, max: int} $value
     *
     * @throws UnknownCharacter when the campaign holds no Character with this id
     * @throws InvalidCharacter when the value breaks a rule
     */
    public function changeCharacterValue(CharacterId $id, string $field, int|array $value): void
    {
        $index = $this->characterIndex($id);
        $character = $this->characters[$index];
        $this->characters = $this->replaced($this->characters, $index, $character->edited($character->name(), $character->tags(), $character->freeTags(), [...$character->values(), $field => $value]));
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
     * @throws TagNotApplicable  when a Tag is limited to other kinds than the entity's
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
     * @throws TagNotApplicable    when a Tag is limited to other kinds than the entity's
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
     * Adds an open Place with weight 1 and no free tags, as a createPlace Effect of the FlowRun
     * does, under a generated id (see GeneratedItemId).
     *
     * @param list<string> $tags release Tag keys of the pinned release
     *
     * @return ?Place the new Place; null (nothing added) when the campaign already holds 500 Places
     *
     * @throws InvalidPlace       when a value breaks a rule
     * @throws UnknownReleaseTag  when a Tag key is not in the pinned release
     * @throws TagNotApplicable   when a Tag is limited to other kinds than Places
     * @throws PlaceAlreadyExists when a Place already holds the generated id
     */
    public function createPlaceByEffect(string $name, array $tags, GameSystemSnapshot $release): ?Place
    {
        if (\count($this->places) >= self::MAX_PLACES) {
            return null;
        }

        return $this->addPlace(GeneratedItemId::place($this->id, \count($this->places)), $name, null, $tags, [], ItemRules::DEFAULT_WEIGHT, $release);
    }

    /**
     * Fills a Fact slot of the pinned release as a fillFact Effect of the FlowRun does: a filled
     * slot takes the new value (its fact id and links stay); an unfilled one gets a new slot fact
     * under a generated id (see GeneratedItemId).
     *
     * @param ?string   $text    the value of a text slot
     * @param ?FactLink $subject the value of an NPC, Thread or Place slot
     *
     * @return ?Fact the slot fact; null (nothing added) when a new one would pass 500 facts
     *
     * @throws \LogicException   when the pinned release declares no slot with this key
     * @throws InvalidFact       when the value breaks a rule
     * @throws UnknownNpc        when the subject is an NPC the campaign does not hold
     * @throws UnknownThread     when the subject is a Thread the campaign does not hold
     * @throws UnknownPlace      when the subject is a Place the campaign does not hold
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
                ? ($this->itemNames(match ($subject->kind) {
                    FactLinkKind::Npc => ItemKind::Npc,
                    FactLinkKind::Thread => ItemKind::Thread,
                    FactLinkKind::Place => ItemKind::Place,
                    FactLinkKind::Fact => throw new \LogicException('A slot fact is never filled with another fact.'),
                }, [$subject->id])[0] ?? '')
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
     * Closes the open Thread that fills a Fact slot (a closeThread Effect of the FlowRun naming a
     * fact slot); nothing happens when the slot is unfilled, holds no Thread or its Thread is
     * already closed.
     */
    public function closeThreadOfFactSlot(string $slot): void
    {
        $subject = $this->facts[$this->factIndexOfSlot($slot) ?? -1] ?? null;
        $link = $subject?->subject();
        if ($link instanceof FactLink && FactLinkKind::Thread === $link->kind) {
            $this->closeThreadsById([$link->id]);
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
     * @throws TagNotApplicable     when a Tag is limited to other kinds than the entity's
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
        ItemRules::assertInRelease($thread->tags(), $release, ItemKind::Thread);
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
     * @throws TagNotApplicable  when a Tag is limited to other kinds than the entity's
     */
    public function editThread(ThreadId $id, string $name, array $tags, array $freeTags, int $weight, GameSystemSnapshot $release): Thread
    {
        $this->assertPinned($release);
        $index = $this->threadIndex($id);
        $edited = $this->threads[$index]->edited($name, $tags, $freeTags, $weight);
        ItemRules::assertInRelease($edited->tags(), $release, ItemKind::Thread);
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
     * Adds an open Place at the end of the list; its release Tags must apply to Places (ADR 0047).
     *
     * @param list<string> $tags     release Tag keys of the pinned release
     * @param list<string> $freeTags
     *
     * @throws InvalidPlace         when a value breaks a rule
     * @throws UnknownReleaseTag    when a Tag key is not in the pinned release
     * @throws TagNotApplicable     when a Tag is limited to other kinds than Places
     * @throws PlaceAlreadyExists   when the campaign holds a Place with this id
     * @throws CampaignLimitReached when the campaign already holds 500 Places
     */
    public function addPlace(PlaceId $id, string $name, ?string $notes, array $tags, array $freeTags, int $weight, GameSystemSnapshot $release): Place
    {
        $this->assertPinned($release);
        if (\count($this->places) >= self::MAX_PLACES) {
            throw CampaignLimitReached::places(self::MAX_PLACES);
        }

        if ($this->place($id) instanceof Place) {
            throw PlaceAlreadyExists::withId($id);
        }

        $place = Place::of($id, $name, $notes, $tags, $freeTags, $weight);
        ItemRules::assertInRelease($place->tags(), $release, ItemKind::Place);
        $this->places = [...$this->places, $place];

        return $place;
    }

    /**
     * Replaces the Place's name, notes, Tags, free tags and weight; its status stays. All or nothing.
     *
     * @param list<string> $tags     release Tag keys of the pinned release
     * @param list<string> $freeTags
     *
     * @throws UnknownPlace      when the campaign holds no Place with this id
     * @throws InvalidPlace      when a value breaks a rule
     * @throws UnknownReleaseTag when a Tag key is not in the pinned release
     * @throws TagNotApplicable  when a Tag is limited to other kinds than Places
     */
    public function editPlace(PlaceId $id, string $name, ?string $notes, array $tags, array $freeTags, int $weight, GameSystemSnapshot $release): Place
    {
        $this->assertPinned($release);
        $index = $this->placeIndex($id);
        $edited = $this->places[$index]->edited($name, $notes, $tags, $freeTags, $weight);
        ItemRules::assertInRelease($edited->tags(), $release, ItemKind::Place);
        $this->places = $this->replaced($this->places, $index, $edited);

        return $edited;
    }

    /**
     * Closes the Place: it is no longer picked or counted. Closing a closed one changes nothing.
     *
     * @throws UnknownPlace when the campaign holds no Place with this id
     */
    public function closePlace(PlaceId $id): void
    {
        $index = $this->placeIndex($id);
        if ($this->places[$index]->isOpen()) {
            $this->places = $this->replaced($this->places, $index, $this->places[$index]->closed());
        }
    }

    /**
     * Reopens the Place. Reopening an open one changes nothing.
     *
     * @throws UnknownPlace when the campaign holds no Place with this id
     */
    public function reopenPlace(PlaceId $id): void
    {
        $index = $this->placeIndex($id);
        if (!$this->places[$index]->isOpen()) {
            $this->places = $this->replaced($this->places, $index, $this->places[$index]->reopened());
        }
    }

    public function place(PlaceId $id): ?Place
    {
        return $this->places[$this->placeIndexOrNull($id) ?? -1] ?? null;
    }

    /**
     * @return list<Place> the open ones first, then the closed ones, each in creation order
     */
    public function places(): array
    {
        return $this->openFirst($this->places);
    }

    /**
     * Adds a free Campaign fact at the end of the list.
     *
     * @param list<FactLink> $links
     *
     * @throws InvalidFact          when a value breaks a rule
     * @throws UnknownNpc           when a link names an NPC the campaign does not hold
     * @throws UnknownThread        when a link names a Thread the campaign does not hold
     * @throws UnknownPlace         when a link names a Place the campaign does not hold
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
     * ones too), or a new NPC, Thread or Place by name, created in the same call (open, weight 1, no
     * tags). An unfilled slot gets a new fact under the given id; a filled one keeps its id and
     * links and takes the new value. All or nothing: a refusal creates nothing, not even the NPC.
     *
     * @throws UnknownFactSlot      when the pinned release declares no slot with this key
     * @throws InvalidFact          when the value does not fit the slot's type, or breaks a rule
     * @throws UnknownNpc           when the subject is an NPC the campaign does not hold
     * @throws UnknownThread        when the subject is a Thread the campaign does not hold
     * @throws UnknownPlace         when the subject is a Place the campaign does not hold
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
            } elseif (FactLinkKind::Thread === $subject->kind) {
                $draft->addThread(ThreadId::fromString($subject->id), $fill->newName, [], [], ItemRules::DEFAULT_WEIGHT, $release);
            } else {
                $draft->addPlace(PlaceId::fromString($subject->id), $fill->newName, null, [], [], ItemRules::DEFAULT_WEIGHT, $release);
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
     * @throws UnknownPlace  when a link names a Place the campaign does not hold
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
     * The number of open NPCs, Threads or Places, or of Characters (which are never closed), carrying all
     * the given release Tag keys (any, when none); free tags never match. What a count condition counts.
     *
     * @param list<string> $tags
     */
    public function openItemCount(ItemKind $of, array $tags): int
    {
        $items = match ($of) {
            ItemKind::Npc => $this->npcs,
            ItemKind::Thread => $this->threads,
            ItemKind::Place => $this->places,
            ItemKind::Character => $this->characters,
        };

        return \count(array_filter($items, static fn (Npc|Thread|Place|Character $item): bool => ($item instanceof Character || $item->isOpen()) && $item->carries($tags)));
    }

    /**
     * The candidates of a pick step: the open NPCs, Threads or Places, or every Character, carrying all
     * the given release Tag keys (any, when none), in list order; free tags never match.
     *
     * @param list<string> $tags
     */
    public function pickCandidates(ItemKind $of, array $tags): PickCandidates
    {
        $items = match ($of) {
            ItemKind::Npc => $this->npcs,
            ItemKind::Thread => $this->threads,
            ItemKind::Place => $this->places,
            ItemKind::Character => $this->characters,
        };

        return new PickCandidates(array_values(array_map(
            static fn (Npc|Thread|Place|Character $item): PickCandidate => new PickCandidate($item->id()->toString(), $item->name(), $item instanceof Character ? 1 : $item->weight()),
            array_filter($items, static fn (Npc|Thread|Place|Character $item): bool => ($item instanceof Character || $item->isOpen()) && $item->carries($tags)),
        )));
    }

    /**
     * The current names of NPCs, Threads, Places or Characters by id, in the order of the ids; an id the campaign does
     * not hold has none.
     *
     * @param list<string> $ids
     *
     * @return list<string>
     */
    public function itemNames(ItemKind $of, array $ids): array
    {
        $items = match ($of) {
            ItemKind::Npc => $this->npcs,
            ItemKind::Thread => $this->threads,
            ItemKind::Place => $this->places,
            ItemKind::Character => $this->characters,
        };
        $names = [];
        foreach ($ids as $id) {
            $item = array_find($items, static fn (Npc|Thread|Place|Character $held): bool => $held->id()->toString() === $id);
            if (null !== $item) {
                $names[] = $item->name();
            }
        }

        return $names;
    }

    /**
     * Completes the FlowRun's current step with a result of its kind.
     *
     * @param ?RandomNumberGenerator $random the source the step's sheet Effects roll their dice with
     *
     * @return list<SheetChange> what the step's sheet Effects changed on the step's Character, in apply order
     *
     * @throws FlowRunNotActive        when the campaign plays freely, guidance is paused or the Flow is complete
     * @throws CheckStepPending        when a check step's roll waits for Keep or Push
     * @throws FlowRunPositionMismatch when the current step is another
     * @throws InvalidStepResult       when the result does not complete the step
     * @throws CampaignLimitReached    when the next scene does not fit the session
     * @throws FlowFormulaFailed       when an Effect's formula cannot be evaluated; nothing changes
     * @throws InsufficientFunds       when a choice option's, a prompt's or a character step's Effect would take the currency under its floor; nothing changes
     *
     * @phpstan-impure
     */
    public function completeFlowStep(string $stepKey, StepResult $result, GameSystemSnapshot $release, \DateTimeImmutable $at, ?RandomNumberGenerator $random = null, ?string $entryId = null): array
    {
        $draft = clone $this;
        $changes = $draft->guided()->completeStep($stepKey, $result, $draft->flowContext($release, $at, $random), $entryId);
        $this->adopt($draft);

        return $changes;
    }

    /**
     * The roll of the FlowRun's current check step that waits for Keep or Push (ADR 0041). Changes nothing.
     *
     * @throws FlowRunNotActive        when the campaign plays freely, guidance is paused or the Flow is complete
     * @throws NoPendingCheck          when no roll pends
     * @throws FlowRunPositionMismatch when the roll pends at another step, or the guided scene is no longer the current scene
     */
    public function pendingFlowCheck(string $stepKey, GameSystemSnapshot $release, \DateTimeImmutable $at): PendingCheck
    {
        return $this->guided()->pendingCheckAt($stepKey, $this->flowContext($release, $at));
    }

    /**
     * Completes the check step whose roll pends on the rolled band (Keep) or the pushed one (Push),
     * as completeFlowStep() completes a step. All or nothing.
     *
     * @param ?string        $pushedBandKey the band of the pushed roll; null keeps the rolled one
     * @param ?SpecialResult $pushedSpecial the special result of the pushed roll, none when the push left none (ADR 0044)
     *
     * @return list<SheetChange> what the step's sheet Effects changed, in apply order
     *
     * @throws FlowRunNotActive        when the campaign plays freely, guidance is paused or the Flow is complete
     * @throws NoPendingCheck          when no roll pends
     * @throws FlowRunPositionMismatch when the roll pends at another step, or the guided scene is no longer the current scene
     * @throws CampaignLimitReached    when the next scene does not fit the session
     * @throws FlowFormulaFailed       when an Effect's formula cannot be evaluated; nothing changes
     *
     * @phpstan-impure
     */
    public function resolvePendingFlowCheck(string $stepKey, ?string $pushedBandKey, GameSystemSnapshot $release, \DateTimeImmutable $at, ?RandomNumberGenerator $random = null, ?SpecialResult $pushedSpecial = null): array
    {
        $draft = clone $this;
        $changes = $draft->guided()->resolvePendingCheck($stepKey, $pushedBandKey, $draft->flowContext($release, $at, $random), $pushedSpecial);
        $this->adopt($draft);

        return $changes;
    }

    /**
     * Checks what the FlowRun's current check step needs before its dice are rolled and answers the
     * Check difficulty to perform with. Changes nothing.
     *
     * @return ?int the step's difficulty, else the given one (null for the check's default)
     *
     * @throws FlowRunNotActive        when the campaign plays freely, guidance is paused or the Flow is complete
     * @throws CheckStepPending        when a check step's roll waits for Keep or Push
     * @throws FlowRunPositionMismatch when the current step is another
     * @throws InvalidStepResult       when the step is no check step, the Character is unknown or not in the scene, or the step fixes the difficulty and one is given
     * @throws FlowFormulaFailed       when the step's difficulty formula divides by zero or overflows
     */
    public function flowCheckDifficulty(string $stepKey, string $characterId, ?int $difficulty, GameSystemSnapshot $release, \DateTimeImmutable $at): ?int
    {
        return $this->guided()->checkDifficulty($stepKey, $characterId, $difficulty, $this->flowContext($release, $at));
    }

    /**
     * Skips the FlowRun's current suggested step.
     *
     * @param ?RandomNumberGenerator $random the source a shop step reached on the way draws its stock with (ADR 0045)
     *
     * @throws FlowRunNotActive        when the campaign plays freely, guidance is paused or the Flow is complete
     * @throws CheckStepPending        when a check step's roll waits for Keep or Push
     * @throws FlowRunPositionMismatch when the current step is another
     * @throws StepCannotBeSkipped     when the step is mandatory
     * @throws CampaignLimitReached    when the next scene does not fit the session
     */
    public function skipFlowStep(string $stepKey, GameSystemSnapshot $release, \DateTimeImmutable $at, ?RandomNumberGenerator $random = null): void
    {
        $draft = clone $this;
        $draft->guided()->skipStep($stepKey, $draft->flowContext($release, $at, $random));
        $this->adopt($draft);
    }

    /**
     * Ends open play in the guided scene ("End scene"): its closing starts.
     *
     * @param int                    $sceneNumber the guided scene, by its number in the session under way
     * @param ?RandomNumberGenerator $random      the source a shop step reached on the way draws its stock with (ADR 0045)
     *
     * @throws FlowRunNotActive        when the campaign plays freely, guidance is paused or the Flow is complete
     * @throws CheckStepPending        when a check step's roll waits for Keep or Push
     * @throws FlowRunPositionMismatch when the FlowRun is not in open play in that scene
     * @throws CampaignLimitReached    when the next scene does not fit the session
     */
    public function endFlowScene(int $sceneNumber, GameSystemSnapshot $release, \DateTimeImmutable $at, ?RandomNumberGenerator $random = null): void
    {
        $draft = clone $this;
        $draft->guided()->endScene($sceneNumber, $draft->flowContext($release, $at, $random));
        $this->adopt($draft);
    }

    /**
     * Picks a Scene Type the FlowRun's scene pick offers; its scene starts.
     *
     * @param ?RandomNumberGenerator $random the source a shop step reached on the way draws its stock with (ADR 0045)
     *
     * @throws FlowRunNotActive        when the campaign plays freely, guidance is paused or the Flow is complete
     * @throws CheckStepPending        when a check step's roll waits for Keep or Push
     * @throws FlowRunPositionMismatch when the FlowRun is not at the scene pick
     * @throws SceneTypeNotOffered     when the pick does not offer it
     * @throws NoCurrentSession        when no session is under way
     * @throws CampaignLimitReached    when the session already holds 200 scenes
     */
    public function pickSceneType(string $sceneTypeKey, GameSystemSnapshot $release, \DateTimeImmutable $at, ?RandomNumberGenerator $random = null): void
    {
        $draft = clone $this;
        $draft->guided()->pickSceneType($sceneTypeKey, $draft->flowContext($release, $at, $random));
        $this->adopt($draft);
    }

    /**
     * Picks the Scene Type of the entry rolled on the oracle table the scene pick rolls on.
     *
     * @param ?RandomNumberGenerator $random the source a shop step reached on the way draws its stock with (ADR 0045)
     *
     * @throws FlowRunNotActive        when the campaign plays freely, guidance is paused or the Flow is complete
     * @throws CheckStepPending        when a check step's roll waits for Keep or Push
     * @throws FlowRunPositionMismatch when the FlowRun is not at the scene pick
     * @throws SceneTypeNotOffered     when the pick does not roll on that table, or the entry names no Scene Type
     * @throws NoCurrentSession        when no session is under way
     * @throws CampaignLimitReached    when the session already holds 200 scenes
     */
    public function pickSceneTypeByOracle(OracleTableResult $result, GameSystemSnapshot $release, \DateTimeImmutable $at, ?RandomNumberGenerator $random = null): void
    {
        $draft = clone $this;
        $draft->guided()->pickSceneTypeByOracle($result, $draft->flowContext($release, $at, $random));
        $this->adopt($draft);
    }

    /**
     * Ends the FlowRun's loop phase ("Move on"): at once at the scene pick, else after the current scene.
     *
     * @param string                 $phaseKey the phase to end, by its key
     * @param ?RandomNumberGenerator $random   the source a shop step reached on the way draws its stock with (ADR 0045)
     *
     * @throws FlowRunNotActive        when the campaign plays freely, guidance is paused or the Flow is complete
     * @throws CheckStepPending        when a check step's roll waits for Keep or Push
     * @throws FlowRunPositionMismatch when the current phase is another, or the guided scene is no longer the current scene
     * @throws MoveOnNotAllowed        when the phase plays once
     * @throws CampaignLimitReached    when the next scene does not fit the session
     */
    public function moveOn(string $phaseKey, GameSystemSnapshot $release, \DateTimeImmutable $at, ?RandomNumberGenerator $random = null): void
    {
        $draft = clone $this;
        $draft->guided()->moveOn($phaseKey, $draft->flowContext($release, $at, $random));
        $this->adopt($draft);
    }

    /**
     * Pauses guidance: the player plays freely until it resumes.
     *
     * @throws FlowRunNotActive when the campaign plays freely, guidance is already paused or the Flow is complete
     * @throws CheckStepPending when a check step's roll waits for Keep or Push
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
     * @param ?RandomNumberGenerator $random the source a shop step reached on the way draws its stock with (ADR 0045)
     *
     * @throws FlowRunNotActive     when the campaign plays freely, guidance is not paused or the Flow is complete
     * @throws CheckStepPending     when a check step's roll waits for Keep or Push
     * @throws CampaignLimitReached when the next scene does not fit the session
     */
    public function resumeGuidance(GameSystemSnapshot $release, \DateTimeImmutable $at, ?RandomNumberGenerator $random = null): void
    {
        $draft = clone $this;
        $draft->guided()->resume($draft->flowContext($release, $at, $random));
        $this->adopt($draft);
    }

    /**
     * What upgrading to the target release would change (ADR 0035): the preview the player confirms.
     * Nothing changes.
     *
     * @param GameSystemSnapshot $pinned the campaign's pinned release
     * @param GameSystemSnapshot $target the release to upgrade to
     *
     * @throws \LogicException            when the first snapshot is not the pinned release
     * @throws UpgradeToAnotherGameSystem when the target belongs to another GameSystem
     * @throws CampaignUpToDate           when the target is not newer than the pinned release
     */
    public function planReleaseUpgrade(GameSystemSnapshot $pinned, GameSystemSnapshot $target): ReleaseUpgradePlan
    {
        $this->assertPinned($pinned);
        if ($target->gameSystemKey() !== $this->gameSystemKey) {
            throw UpgradeToAnotherGameSystem::of($this->gameSystemKey, $target->gameSystemKey());
        }

        if ($target->releaseVersion() <= $this->releaseVersion) {
            throw CampaignUpToDate::at($this->releaseVersion, $target->releaseVersion());
        }

        return new ReleaseUpgradePlanner($pinned, $target)->plan($this->characters, $this->npcs, $this->threads, $this->trackerValues, $this->facts, $this->flowKey, $this->flowRun instanceof FlowRun);
    }

    /**
     * Moves the campaign to the target release (ADR 0035), exactly as the confirmed plan says: the
     * plan is computed again and its fingerprint must be the one the player confirmed. Character
     * values, Tags, Tracker values and Campaign facts are fitted to the target release, guidance
     * restarts on the target Flow (paused when it was paused), and the campaign is pinned to the
     * target. All or nothing; sessions, scenes and the journal stay as they are.
     *
     * @param string             $fingerprint the fingerprint of the plan the player confirmed
     * @param \DateTimeImmutable $at          when a paused guidance is paused again
     *
     * @throws \LogicException            when the first snapshot is not the pinned release
     * @throws UpgradeToAnotherGameSystem when the target belongs to another GameSystem
     * @throws CampaignUpToDate           when the target is not newer than the pinned release
     * @throws ReleaseUpgradeChanged      when the plan is no longer the confirmed one
     */
    public function upgradeRelease(GameSystemSnapshot $pinned, GameSystemSnapshot $target, string $fingerprint, \DateTimeImmutable $at): void
    {
        if (!hash_equals($this->planReleaseUpgrade($pinned, $target)->fingerprint(), $fingerprint)) {
            throw ReleaseUpgradeChanged::sincePreview();
        }

        $planner = new ReleaseUpgradePlanner($pinned, $target);
        $draft = clone $this;
        $draft->characters = array_map(static fn (Character $character): Character => Character::of($character->id(), $character->name(), $planner->keptTags($character->tags(), ItemKind::Character), $character->freeTags(), $planner->fit($character)->values, $character->promotedFrom()), $this->characters);
        $draft->npcs = array_map(static fn (Npc $npc): Npc => $npc->edited($npc->name(), $npc->agenda(), $planner->keptTags($npc->tags(), ItemKind::Npc), $npc->freeTags(), $npc->weight()), $this->npcs);
        $draft->threads = array_map(static fn (Thread $thread): Thread => $thread->edited($thread->name(), $planner->keptTags($thread->tags(), ItemKind::Thread), $thread->freeTags(), $thread->weight()), $this->threads);
        $draft->trackerValues = $planner->keptTrackerValues($this->trackerValues);
        $draft->facts = array_map(fn (Fact $fact): Fact => null !== $fact->slot() && !$target->factSlot($fact->slot()) instanceof FactSlot ? $planner->freed($fact, $this->npcs, $this->threads) : $fact, $this->facts);
        $draft->flowKey = $planner->targetFlowKey($this->flowKey);
        $draft->flowRun = null === $draft->flowKey ? null : FlowRun::start();
        if (FlowRunStatus::Paused === $this->flowRun?->status()) {
            $draft->flowRun?->pause($at);
        }

        $draft->assertFactsConsistent();
        $this->adopt($draft);
        [$this->gameSystemKey, $this->releaseVersion, $this->gameSystemName, $this->flowKey] = [$target->gameSystemKey(), $target->releaseVersion(), $target->name(), $draft->flowKey];
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
        return $this->flowRun?->view($release, $this->playedFlow($release), $this->currentSession()?->number(), $this->trackerValuesOf($release), $this->factValues($release), $this->pickedNames(), fn (PickStep $step): PickCandidates => $this->pickCandidates($step->of, $step->tags), fn (bool $inScene): array => $this->namedCharacters($inScene ? $this->currentScene()?->characters() : $this->currentSession()?->party()));
    }

    /**
     * @param ?list<CharacterId> $ids
     *
     * @return list<PickedItem> the Characters of the campaign among the ids, by id and current name
     */
    private function namedCharacters(?array $ids): array
    {
        $named = array_map(fn (CharacterId $id): ?PickedItem => ($character = $this->character($id)) instanceof Character ? new PickedItem($id->toString(), $character->name()) : null, $ids ?? []);

        return array_values(array_filter($named));
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
     * What the player sees of the FlowRun, which must be guiding and free to move: the view a FlowRun
     * command acts on.
     *
     * @throws FlowRunNotActive when the campaign plays freely, guidance is paused or the Flow is complete
     * @throws CheckStepPending when a check step's roll waits for Keep or Push
     * @throws UnknownFlow      when the pinned release has no Flow with the campaign's key
     */
    public function activeFlowRunView(GameSystemSnapshot $release): FlowRunView
    {
        $view = $this->flowRunView($release) ?? throw FlowRunNotActive::none();
        if (FlowRunStatus::Active !== $view->status) {
            throw FlowRunNotActive::toPlay($view->status);
        }

        $this->flowRun?->assertNoPendingCheck();

        return $view;
    }

    /**
     * Notes what the Flow decided on its own (called by the FlowRun's context while a command runs).
     *
     * @internal
     */
    public function noteFlow(FlowNote $note): void
    {
        $this->flowNotes[] = $note;
    }

    /**
     * The notes the last FlowRun command collected, in apply order; they are handed out once.
     *
     * @return list<FlowNote>
     *
     * @phpstan-impure
     */
    public function takeFlowNotes(): array
    {
        $notes = $this->flowNotes;
        $this->flowNotes = [];

        return $notes;
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
        [$this->sessions, $this->trackerValues, $this->flowRun, $this->npcs, $this->threads, $this->places, $this->facts, $this->characters, $this->flowNotes] = [$draft->sessions, $draft->trackerValues, $draft->flowRun, $draft->npcs, $draft->threads, $draft->places, $draft->facts, $draft->characters, $draft->flowNotes];
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

    private function flowContext(GameSystemSnapshot $release, \DateTimeImmutable $at, ?RandomNumberGenerator $random = null): FlowRunContext
    {
        return new FlowRunContext($this, $release, $this->playedFlow($release), $at, $random);
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
     * @throws UnknownPlace
     */
    private function placeIndex(PlaceId $id): int
    {
        return $this->placeIndexOrNull($id) ?? throw UnknownPlace::withId($id);
    }

    private function placeIndexOrNull(PlaceId $id): ?int
    {
        foreach ($this->places as $index => $place) {
            if ($place->id()->equals($id)) {
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
     * @throws UnknownPlace         when a link names a Place the campaign does not hold
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
     * subject names an NPC, Thread or fact of this campaign, and every promotion is named by both
     * its NPC and its Character (see assertPromotionsConsistent()), and every party and scene cast
     * names Characters of this campaign (see assertPartiesConsistent()). Called by reconstitute() and, for a
     * stored row, after Doctrine loads it (the facts column cannot see the NPCs and Threads).
     *
     * @throws FactAlreadyExists    when two facts share an id
     * @throws CampaignLimitReached when there are more than 500 facts
     * @throws InvalidFact          when two facts fill one slot
     * @throws UnknownNpc           when a link or subject names an NPC the campaign does not hold
     * @throws UnknownThread        when a link or subject names a Thread the campaign does not hold
     * @throws UnknownPlace         when a link or subject names a Place the campaign does not hold
     * @throws UnknownFact          when a link names a fact the campaign does not hold
     * @throws InvalidPromotion     when a promotion is named by one side only
     * @throws InvalidSessionParty  when a session's party names a Character the campaign does not hold
     * @throws InvalidSceneCast     when a scene's Characters name one the campaign does not hold
     */
    public function assertFactsConsistent(): void
    {
        $this->assertPromotionsConsistent();
        $this->assertPartiesConsistent();

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
     * Every Character a session's party or a scene's Characters cast names is a Character of the
     * campaign (Characters are never deleted, so a missing one is corrupt data).
     *
     * @throws InvalidSessionParty when a party names a Character the campaign does not hold
     * @throws InvalidSceneCast    when a scene casts a Character the campaign does not hold
     */
    private function assertPartiesConsistent(): void
    {
        foreach ($this->sessions as $session) {
            foreach ($session->party() as $id) {
                if (!$this->character($id) instanceof Character) {
                    throw InvalidSessionParty::unknownCharacter($session->number(), $id);
                }
            }

            foreach ($session->scenes() as $scene) {
                foreach ($scene->characters() as $id) {
                    if (!$this->character($id) instanceof Character) {
                        throw InvalidSceneCast::unknownCharacter($session->number(), $scene->number(), $id);
                    }
                }
            }
        }
    }

    /**
     * A promoted NPC names a Character of the campaign that names it back, and a Character that
     * names an NPC is named back by an NPC of the campaign.
     *
     * @throws InvalidPromotion when a promotion is named by one side only
     */
    private function assertPromotionsConsistent(): void
    {
        foreach ($this->npcs as $npc) {
            $characterId = $npc->promotedTo();
            if ($characterId instanceof CharacterId && !($this->character($characterId)?->promotedFrom()?->equals($npc->id()) ?? false)) {
                throw InvalidPromotion::because(\sprintf('NPC "%s" was promoted to Character "%s", which does not come from it.', $npc->id()->toString(), $characterId->toString()));
            }
        }

        foreach ($this->characters as $character) {
            $npcId = $character->promotedFrom();
            if ($npcId instanceof NpcId && !($this->npc($npcId)?->promotedTo()?->equals($character->id()) ?? false)) {
                throw InvalidPromotion::because(\sprintf('Character "%s" comes from NPC "%s", which was not promoted to it.', $character->id()->toString(), $npcId->toString()));
            }
        }
    }

    /**
     * @throws UnknownNpc
     * @throws UnknownThread
     * @throws UnknownPlace
     * @throws UnknownFact
     */
    private function assertLinkResolves(FactLink $link): void
    {
        match ($link->kind) {
            FactLinkKind::Npc => $this->npcIndex(NpcId::fromString($link->id)),
            FactLinkKind::Thread => $this->threadIndex(ThreadId::fromString($link->id)),
            FactLinkKind::Place => $this->placeIndex(PlaceId::fromString($link->id)),
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
     * @template T of Npc|Thread|Place|Fact|Character
     *
     * @param list<T> $items
     * @param T       $item
     *
     * @return list<T> a copy with the item at the index replaced, so that the stored list is seen to change
     */
    private function replaced(array $items, int $index, Npc|Thread|Place|Fact|Character $item): array
    {
        $items[$index] = $item;

        return array_values($items);
    }

    /**
     * @template T of Npc|Thread|Place
     *
     * @param list<T> $items
     *
     * @return list<T>
     */
    private function openFirst(array $items): array
    {
        return [
            ...array_filter($items, static fn (Npc|Thread|Place $item): bool => $item->isOpen()),
            ...array_filter($items, static fn (Npc|Thread|Place $item): bool => !$item->isOpen()),
        ];
    }

    /**
     * While a session is under way, a new Character joins its party and the current scene's
     * cast (at the end of the Characters); when it was promoted from an NPC, that NPC leaves the
     * scene's NPCs. Earlier scenes and sessions stay as they are. Nothing changes without a session
     * under way.
     */
    private function joinCurrentSession(CharacterId $id, ?NpcId $replacing = null): void
    {
        $session = $this->currentSession();
        if (!$session instanceof Session) {
            return;
        }

        $session = $session->withParty([...$session->party(), $id]);
        $scene = $session->currentScene();
        if ($scene instanceof Scene) {
            $cast = $replacing instanceof NpcId
                ? array_values(array_filter($scene->cast(), static fn (NpcId $member): bool => !$member->equals($replacing)))
                : $scene->cast();
            $session = $session->withCurrentScene($scene->withCharacters([...$scene->characters(), $id])->withCast($cast));
        }

        $this->sessions = [...\array_slice($this->sessions, 0, -1), $session];
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
     * Whether the title is what defaultSceneTitle() gives some scene of this Scene Type: the name,
     * cut to fit, and a space and a number without leading zeros.
     */
    private function isDefaultSceneTitle(string $title, SceneType $sceneType): bool
    {
        if (1 !== preg_match('/^(.*) ([1-9]\d*)$/su', $title, $parts)) {
            return false;
        }

        return mb_substr($sceneType->name, 0, Scene::MAX_TITLE_LENGTH - \strlen(' '.$parts[2])) === $parts[1];
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
