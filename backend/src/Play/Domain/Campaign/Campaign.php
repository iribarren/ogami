<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunContext;
use App\Play\Domain\Campaign\FlowRun\FlowRunNotActive;
use App\Play\Domain\Campaign\FlowRun\FlowRunPositionMismatch;
use App\Play\Domain\Campaign\FlowRun\InvalidStepResult;
use App\Play\Domain\Campaign\FlowRun\MoveOnNotAllowed;
use App\Play\Domain\Campaign\FlowRun\SceneTypeNotOffered;
use App\Play\Domain\Campaign\FlowRun\StepCannotBeSkipped;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\GameSystem\Flow\Flow;
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
 * take the pinned release.
 *
 * State is kept as scalars (id, owner, name, pinned release fields, Flow key) plus the session list and the
 * tracker values, so an adapter can map it and rebuild it with reconstitute(). The FlowRun is not
 * stored yet (play-flow-run slice 14): a stored campaign has none.
 */
final class Campaign
{
    public const int MAX_NAME_LENGTH = 100;

    public const int MAX_SESSIONS = 500;

    /**
     * Concurrency token, owned by the persistence adapter: it counts saves so that a save of a
     * stale copy is refused (CampaignModifiedConcurrently). The model never changes it.
     */
    private int $version = 1;

    private ?FlowRun $flowRun = null;

    /**
     * @param list<Session>      $sessions      in number order
     * @param array<string, int> $trackerValues by Tracker key
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

        $campaign = self::reconstitute($id, $ownerId, $name, $pinnedRelease, $createdAt, [], $trackerValues, $flow?->key);
        $campaign->flowRun = $flow instanceof Flow ? FlowRun::start() : null;

        return $campaign;
    }

    /**
     * Rebuilds a stored campaign, e.g. from persistence. No rule is checked again.
     *
     * @param list<Session>      $sessions      in number order
     * @param array<string, int> $trackerValues by Tracker key
     * @param ?string            $flowKey       the key of the Flow played, or null for free play
     */
    public static function reconstitute(CampaignId $id, string $ownerId, string $name, PinnedRelease $pinnedRelease, \DateTimeImmutable $createdAt, array $sessions, array $trackerValues = [], ?string $flowKey = null): self
    {
        return new self(
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
        );
    }

    /**
     * Starts session n + 1 (the first is 1). It becomes the current session, with no scene yet;
     * a session still under way stays as it is. A FlowRun waiting at a scene pick with one Scene
     * Type starts that scene.
     *
     * @param ?GameSystemSnapshot $release the pinned release; without it a FlowRun is not told (until
     *                                     play-flow-run slice 14 drives FlowRuns from the handlers)
     *
     * @throws CampaignLimitReached when the campaign already holds 500 sessions
     */
    public function startSession(\DateTimeImmutable $startedAt, ?GameSystemSnapshot $release = null): Session
    {
        if (\count($this->sessions) >= self::MAX_SESSIONS) {
            throw CampaignLimitReached::sessions(self::MAX_SESSIONS);
        }

        $session = Session::start(\count($this->sessions) + 1, $startedAt);
        $this->sessions[] = $session;
        if ($release instanceof GameSystemSnapshot) {
            $this->flowRun?->sessionStarted($this->flowContext($release, $startedAt));
        }

        return $session;
    }

    /**
     * Ends the current session: its scenes stay, and no scene starts until the next session.
     *
     * @param ?int $number the number of the session the caller means to end, or null for whichever is under way
     *
     * @return Session the ended session
     *
     * @throws NoCurrentSession when no session is under way (none has started, or it has ended), or
     *                          another session than the named one is
     */
    public function endSession(\DateTimeImmutable $endedAt, ?int $number = null): Session
    {
        $session = $this->currentSession() ?? throw NoCurrentSession::toEndSession();
        if (null !== $number && $session->number() !== $number) {
            throw NoCurrentSession::numbered($number);
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
     * journal entries stay, a default numbered title included.
     *
     * @param SceneType $sceneType a Scene Type of the pinned release
     *
     * @throws NoCurrentScene          when the current session has no scene, or no session is under way
     * @throws HookSceneHasNoSceneType when the current scene is a hook Scene
     */
    public function switchSceneType(SceneType $sceneType): Scene
    {
        $session = $this->currentSession();
        $scene = $session?->currentScene() ?? throw NoCurrentScene::toSwitchSceneType();
        $switched = $scene->withSceneType($sceneType->key);
        $this->sessions = [...\array_slice($this->sessions, 0, -1), $session->withCurrentScene($switched)];

        return $switched;
    }

    /**
     * Sets a Tracker's value by hand, clamped to its range.
     *
     * @return int the value kept
     */
    public function setTrackerValue(Tracker $tracker, int $value): int
    {
        return $this->applyTrackerChange($tracker, TrackerOperation::Set, $value);
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
     * Completes the FlowRun's current step with a result of its kind.
     *
     * @throws FlowRunNotActive        when the campaign plays freely or the Flow is complete
     * @throws FlowRunPositionMismatch when the current step is another
     * @throws InvalidStepResult       when the result does not complete the step
     * @throws CampaignLimitReached    when the next scene does not fit the session
     */
    public function completeFlowStep(string $stepKey, StepResult $result, GameSystemSnapshot $release, \DateTimeImmutable $at): void
    {
        $this->guided()->completeStep($stepKey, $result, $this->flowContext($release, $at));
    }

    /**
     * Skips the FlowRun's current suggested step.
     *
     * @throws FlowRunNotActive        when the campaign plays freely or the Flow is complete
     * @throws FlowRunPositionMismatch when the current step is another
     * @throws StepCannotBeSkipped     when the step is mandatory
     * @throws CampaignLimitReached    when the next scene does not fit the session
     */
    public function skipFlowStep(string $stepKey, GameSystemSnapshot $release, \DateTimeImmutable $at): void
    {
        $this->guided()->skipStep($stepKey, $this->flowContext($release, $at));
    }

    /**
     * Ends open play in the guided scene ("End scene"): its closing starts.
     *
     * @param int $sceneNumber the guided scene, by its number in the session under way
     *
     * @throws FlowRunNotActive        when the campaign plays freely or the Flow is complete
     * @throws FlowRunPositionMismatch when the FlowRun is not in open play in that scene
     * @throws CampaignLimitReached    when the next scene does not fit the session
     */
    public function endFlowScene(int $sceneNumber, GameSystemSnapshot $release, \DateTimeImmutable $at): void
    {
        $this->guided()->endScene($sceneNumber, $this->flowContext($release, $at));
    }

    /**
     * Picks a Scene Type the FlowRun's scene pick offers; its scene starts.
     *
     * @throws FlowRunNotActive        when the campaign plays freely or the Flow is complete
     * @throws FlowRunPositionMismatch when the FlowRun is not at the scene pick
     * @throws SceneTypeNotOffered     when the pick does not offer it
     * @throws NoCurrentSession        when no session is under way
     * @throws CampaignLimitReached    when the session already holds 200 scenes
     */
    public function pickSceneType(string $sceneTypeKey, GameSystemSnapshot $release, \DateTimeImmutable $at): void
    {
        $this->guided()->pickSceneType($sceneTypeKey, $this->flowContext($release, $at));
    }

    /**
     * Picks the Scene Type of the entry rolled on the oracle table the scene pick rolls on.
     *
     * @throws FlowRunNotActive        when the campaign plays freely or the Flow is complete
     * @throws FlowRunPositionMismatch when the FlowRun is not at the scene pick
     * @throws SceneTypeNotOffered     when the pick does not roll on that table, or the entry names no Scene Type
     * @throws NoCurrentSession        when no session is under way
     * @throws CampaignLimitReached    when the session already holds 200 scenes
     */
    public function pickSceneTypeByOracle(OracleTableResult $result, GameSystemSnapshot $release, \DateTimeImmutable $at): void
    {
        $this->guided()->pickSceneTypeByOracle($result, $this->flowContext($release, $at));
    }

    /**
     * Ends the FlowRun's loop phase at its scene pick ("Move on").
     *
     * @throws FlowRunNotActive        when the campaign plays freely or the Flow is complete
     * @throws FlowRunPositionMismatch when the FlowRun is not at the scene pick
     * @throws MoveOnNotAllowed        when the phase plays once
     * @throws CampaignLimitReached    when the next scene does not fit the session
     */
    public function moveOn(GameSystemSnapshot $release, \DateTimeImmutable $at): void
    {
        $this->guided()->moveOn($this->flowContext($release, $at));
    }

    /**
     * The campaign's guidance along its Flow; null when played freely.
     */
    public function flowRun(): ?FlowRun
    {
        return $this->flowRun;
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

    private function flowContext(GameSystemSnapshot $release, \DateTimeImmutable $at): FlowRunContext
    {
        return new FlowRunContext($this, $release, $this->playedFlow($release), $at);
    }

    /**
     * @throws UnknownFlow when the pinned release has no Flow with the campaign's key
     */
    private function playedFlow(GameSystemSnapshot $release): Flow
    {
        if ($release->gameSystemKey() !== $this->gameSystemKey || $release->releaseVersion() !== $this->releaseVersion) {
            throw new \LogicException('A FlowRun plays the campaign\'s pinned release.');
        }

        return $release->flow((string) $this->flowKey) ?? throw UnknownFlow::withKey((string) $this->flowKey);
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
