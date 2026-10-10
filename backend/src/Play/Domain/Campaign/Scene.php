<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

/**
 * A unit of story within a session. Numbered from 1 within its session; its identity is the
 * campaign, the session number and its own number.
 *
 * Its kind is `scene` (play, with an optional Scene Type key of the pinned release) or `hook`
 * (a phase boundary, with its hook name and no Scene Type). Only the Scene Type of a scene of play
 * changes after it starts, through withSceneType(), which returns a new Scene.
 */
final readonly class Scene
{
    public const int MAX_TITLE_LENGTH = 100;

    private function __construct(
        private int $number,
        private string $title,
        private \DateTimeImmutable $startedAt,
        private SceneKind $kind,
        private ?string $sceneType,
        private ?Hook $hook,
    ) {
    }

    /**
     * Starts a scene of play.
     *
     * @param ?string $sceneType the key of a Scene Type of the pinned release, or null
     *
     * @throws InvalidSceneTitle when the trimmed title is blank or longer than 100 characters
     */
    public static function start(int $number, string $title, \DateTimeImmutable $startedAt, ?string $sceneType = null): self
    {
        return new self($number, self::checkedTitle($title), $startedAt, SceneKind::Scene, $sceneType, null);
    }

    /**
     * Starts a hook Scene for a phase boundary.
     *
     * @throws InvalidSceneTitle when the trimmed title is blank or longer than 100 characters
     */
    public static function startHook(int $number, Hook $hook, string $title, \DateTimeImmutable $startedAt): self
    {
        return new self($number, self::checkedTitle($title), $startedAt, SceneKind::Hook, null, $hook);
    }

    /**
     * Rebuilds a stored scene, e.g. from persistence. No rule is checked again. A scene stored
     * before scenes had a kind is a scene of play without a Scene Type.
     */
    public static function reconstitute(int $number, string $title, \DateTimeImmutable $startedAt, SceneKind $kind = SceneKind::Scene, ?string $sceneType = null, ?Hook $hook = null): self
    {
        return new self($number, $title, $startedAt, $kind, $sceneType, $hook);
    }

    /**
     * The same scene with another Scene Type; its number, title and start stay.
     *
     * @throws HookSceneHasNoSceneType when this is a hook Scene
     */
    public function withSceneType(string $sceneType): self
    {
        if ($this->hook instanceof Hook) {
            throw HookSceneHasNoSceneType::toSwitch($this->hook);
        }

        return new self($this->number, $this->title, $this->startedAt, $this->kind, $sceneType, null);
    }

    public function number(): int
    {
        return $this->number;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function startedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function kind(): SceneKind
    {
        return $this->kind;
    }

    /**
     * The key of the scene's Scene Type in the pinned release; null for a hook Scene or a scene
     * started without one.
     */
    public function sceneType(): ?string
    {
        return $this->sceneType;
    }

    /**
     * The hook a hook Scene records; null for a scene of play.
     */
    public function hook(): ?Hook
    {
        return $this->hook;
    }

    /**
     * @throws InvalidSceneTitle
     */
    private static function checkedTitle(string $title): string
    {
        $title = trim($title);
        if ('' === $title) {
            throw InvalidSceneTitle::blank();
        }

        $length = mb_strlen($title);
        if ($length > self::MAX_TITLE_LENGTH) {
            throw InvalidSceneTitle::tooLong(self::MAX_TITLE_LENGTH, $length);
        }

        return $title;
    }
}
