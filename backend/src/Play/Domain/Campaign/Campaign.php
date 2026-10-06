<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

/**
 * A solo player's ongoing game, pinned to one GameSystem release (ADR 0014). Play is organized in
 * sessions and scenes: the current session is the latest one, the current scene is the latest
 * scene of the current session.
 *
 * State is kept as scalars (id, owner, name, pinned release fields) plus the session list, so an
 * adapter can map it and rebuild it with reconstitute().
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

    /**
     * @param list<Session> $sessions in number order
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
    ) {
    }

    /**
     * @throws InvalidCampaignOwner when the owner id is blank
     * @throws InvalidCampaignName  when the trimmed name is blank or longer than 100 characters
     */
    public static function create(CampaignId $id, string $ownerId, string $name, PinnedRelease $pinnedRelease, \DateTimeImmutable $createdAt): self
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

        return self::reconstitute($id, $ownerId, $name, $pinnedRelease, $createdAt, []);
    }

    /**
     * Rebuilds a stored campaign, e.g. from persistence. No rule is checked again.
     *
     * @param list<Session> $sessions in number order
     */
    public static function reconstitute(CampaignId $id, string $ownerId, string $name, PinnedRelease $pinnedRelease, \DateTimeImmutable $createdAt, array $sessions): self
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
        );
    }

    /**
     * Starts session n + 1 (the first is 1). It becomes the current session, with no scene yet.
     *
     * @throws CampaignLimitReached when the campaign already holds 500 sessions
     */
    public function startSession(\DateTimeImmutable $startedAt): Session
    {
        if (\count($this->sessions) >= self::MAX_SESSIONS) {
            throw CampaignLimitReached::sessions(self::MAX_SESSIONS);
        }

        $session = Session::start(\count($this->sessions) + 1, $startedAt);
        $this->sessions[] = $session;

        return $session;
    }

    /**
     * Starts scene m + 1 of the current session (the first is 1). It becomes the current scene.
     *
     * @throws NoCurrentSession     when no session has started
     * @throws CampaignLimitReached when the current session already holds 200 scenes
     * @throws InvalidSceneTitle    when the trimmed title is blank or longer than 100 characters
     */
    public function startScene(string $title, \DateTimeImmutable $startedAt): Scene
    {
        if ([] === $this->sessions) {
            throw NoCurrentSession::toStartScene();
        }

        $last = array_key_last($this->sessions);
        $session = $this->sessions[$last]->withNewScene($title, $startedAt);
        $this->sessions[$last] = $session;

        return $session->currentScene() ?? throw new \LogicException('A scene was just added.');
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
     * @return list<Session> in number order
     */
    public function sessions(): array
    {
        return $this->sessions;
    }

    /**
     * The latest session, or null before the first one starts.
     */
    public function currentSession(): ?Session
    {
        return array_last($this->sessions);
    }

    /**
     * The latest scene of the current session, or null when there is none.
     */
    public function currentScene(): ?Scene
    {
        return $this->currentSession()?->currentScene();
    }
}
