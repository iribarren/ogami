<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

/**
 * One sitting of play within a campaign. Numbered from 1 within its campaign; its identity is the
 * campaign and its number. Immutable: adding a scene returns a new Session, and the Campaign
 * aggregate replaces its current session.
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
    ) {
    }

    public static function start(int $number, \DateTimeImmutable $startedAt): self
    {
        return new self($number, $startedAt, []);
    }

    /**
     * Rebuilds a stored session, e.g. from persistence.
     *
     * @param list<Scene> $scenes in number order
     */
    public static function reconstitute(int $number, \DateTimeImmutable $startedAt, array $scenes): self
    {
        return new self($number, $startedAt, $scenes);
    }

    /**
     * @throws CampaignLimitReached when the session already holds 200 scenes
     * @throws InvalidSceneTitle    when the trimmed title is blank or longer than 100 characters
     */
    public function withNewScene(string $title, \DateTimeImmutable $startedAt): self
    {
        if (\count($this->scenes) >= self::MAX_SCENES) {
            throw CampaignLimitReached::scenes(self::MAX_SCENES);
        }

        return new self($this->number, $this->startedAt, [...$this->scenes, Scene::start(\count($this->scenes) + 1, $title, $startedAt)]);
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
}
