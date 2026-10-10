<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

/**
 * One sitting of play within a campaign. Numbered from 1 within its campaign; its identity is the
 * campaign and its number. It is under way until it ends (endedAt). Immutable: adding or changing a
 * scene, or ending it, returns a new Session, and the Campaign aggregate replaces its current
 * session.
 */
final readonly class Session
{
    public const int MAX_SCENES = 200;

    /**
     * @param list<Scene> $scenes in number order
     */
    private function __construct(
        private int $number,
        private \DateTimeImmutable $startedAt,
        private array $scenes,
        private ?\DateTimeImmutable $endedAt,
    ) {
    }

    public static function start(int $number, \DateTimeImmutable $startedAt): self
    {
        return new self($number, $startedAt, [], null);
    }

    /**
     * Rebuilds a stored session, e.g. from persistence. A session stored without an end is under way.
     *
     * @param list<Scene> $scenes in number order
     */
    public static function reconstitute(int $number, \DateTimeImmutable $startedAt, array $scenes, ?\DateTimeImmutable $endedAt = null): self
    {
        return new self($number, $startedAt, $scenes, $endedAt);
    }

    /**
     * The same session, ended; its scenes stay.
     */
    public function end(\DateTimeImmutable $endedAt): self
    {
        if ($this->hasEnded()) {
            throw new \LogicException('The session has already ended.');
        }

        return new self($this->number, $this->startedAt, $this->scenes, $endedAt);
    }

    /**
     * Adds scene m + 1, a scene of play.
     *
     * @param ?string $sceneType the key of a Scene Type of the pinned release, or null
     *
     * @throws CampaignLimitReached when the session already holds 200 scenes
     * @throws InvalidSceneTitle    when the trimmed title is blank or longer than 100 characters
     */
    public function withNewScene(string $title, \DateTimeImmutable $startedAt, ?string $sceneType = null): self
    {
        return $this->withScene(static fn (int $number): Scene => Scene::start($number, $title, $startedAt, $sceneType));
    }

    /**
     * Adds scene m + 1, a hook Scene.
     *
     * @throws CampaignLimitReached when the session already holds 200 scenes
     * @throws InvalidSceneTitle    when the trimmed title is blank or longer than 100 characters
     */
    public function withNewHookScene(Hook $hook, string $title, \DateTimeImmutable $startedAt): self
    {
        return $this->withScene(static fn (int $number): Scene => Scene::startHook($number, $hook, $title, $startedAt));
    }

    /**
     * Replaces the current scene with a changed copy of it (same number).
     */
    public function withCurrentScene(Scene $scene): self
    {
        $last = array_key_last($this->scenes);
        if (null === $last || $this->scenes[$last]->number() !== $scene->number()) {
            throw new \LogicException('Only the current scene can be replaced.');
        }

        return new self($this->number, $this->startedAt, [...\array_slice($this->scenes, 0, -1), $scene], $this->endedAt);
    }

    public function number(): int
    {
        return $this->number;
    }

    public function startedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    /**
     * When the session ended; null while it is under way.
     */
    public function endedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function hasEnded(): bool
    {
        return $this->endedAt instanceof \DateTimeImmutable;
    }

    /**
     * @return list<Scene> in number order
     */
    public function scenes(): array
    {
        return $this->scenes;
    }

    /**
     * The latest scene, or null right after the session starts.
     */
    public function currentScene(): ?Scene
    {
        return array_last($this->scenes);
    }

    /**
     * @param \Closure(int): Scene $start builds the new scene from its number
     *
     * @throws CampaignLimitReached
     * @throws InvalidSceneTitle
     */
    private function withScene(\Closure $start): self
    {
        if (\count($this->scenes) >= self::MAX_SCENES) {
            throw CampaignLimitReached::scenes(self::MAX_SCENES);
        }

        return new self($this->number, $this->startedAt, [...$this->scenes, $start(\count($this->scenes) + 1)], $this->endedAt);
    }
}
