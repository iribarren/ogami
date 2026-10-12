<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

use App\Play\Domain\Campaign\Lists\NpcId;

/**
 * A unit of story within a session. Numbered from 1 within its session; its identity is the
 * campaign, the session number and its own number.
 *
 * Its kind is `scene` (play, with an optional Scene Type key of the pinned release) or `hook`
 * (a phase boundary, with its hook name and no Scene Type). Only the title and the Scene Type of a
 * scene of play, and the cast of either kind, change after it starts, through withTitle(),
 * withSceneType() and withCast(), which return a new Scene. The cast is the NPCs in the scene: an
 * ordered list without repeats.
 */
final readonly class Scene
{
    public const int MAX_TITLE_LENGTH = 100;

    /**
     * @param list<NpcId> $cast the NPCs in the scene, in the order they joined
     */
    private function __construct(
        private int $number,
        private string $title,
        private \DateTimeImmutable $startedAt,
        private SceneKind $kind,
        private ?string $sceneType,
        private ?Hook $hook,
        private array $cast = [],
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
     * Rebuilds a stored scene, e.g. from persistence. Only the kind is checked again: a hook Scene
     * names its hook and a scene of play has none, as the sessions reader requires. A scene stored
     * before scenes had a kind is a scene of play without a Scene Type; one stored before scenes had
     * a cast has an empty one.
     *
     * @param list<NpcId> $cast the NPCs in the scene, without repeats
     *
     * @throws InvalidSceneKind when the kind and the hook disagree
     * @throws InvalidSceneCast when the cast lists an NPC twice
     */
    public static function reconstitute(int $number, string $title, \DateTimeImmutable $startedAt, SceneKind $kind = SceneKind::Scene, ?string $sceneType = null, ?Hook $hook = null, array $cast = []): self
    {
        if (SceneKind::Hook === $kind && !$hook instanceof Hook) {
            throw InvalidSceneKind::hookSceneWithoutHook();
        }

        if (SceneKind::Scene === $kind && $hook instanceof Hook) {
            throw InvalidSceneKind::sceneOfPlayWithHook($hook);
        }

        return new self($number, $title, $startedAt, $kind, $sceneType, $hook, self::checkedCast($cast));
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

        return new self($this->number, $this->title, $this->startedAt, $this->kind, $sceneType, null, $this->cast);
    }

    /**
     * The same scene with another title; its number, kind, Scene Type and start stay.
     *
     * @throws InvalidSceneTitle when the trimmed title is blank or longer than 100 characters
     */
    public function withTitle(string $title): self
    {
        return new self($this->number, self::checkedTitle($title), $this->startedAt, $this->kind, $this->sceneType, $this->hook, $this->cast);
    }

    /**
     * The same scene with another cast; everything else stays.
     *
     * @param list<NpcId> $cast
     *
     * @throws InvalidSceneCast when the cast lists an NPC twice
     */
    public function withCast(array $cast): self
    {
        return new self($this->number, $this->title, $this->startedAt, $this->kind, $this->sceneType, $this->hook, self::checkedCast($cast));
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
     * The NPCs in the scene, in the order they joined, each once. The player edits it for the
     * current scene (Campaign::editCurrentSceneCast()); picks and NPC creation by a Flow join it.
     *
     * @return list<NpcId>
     */
    public function cast(): array
    {
        return $this->cast;
    }

    /**
     * @param list<NpcId> $cast
     *
     * @return list<NpcId>
     *
     * @throws InvalidSceneCast
     */
    private static function checkedCast(array $cast): array
    {
        $seen = [];
        foreach ($cast as $id) {
            if (isset($seen[$id->toString()])) {
                throw InvalidSceneCast::listedTwice($id);
            }

            $seen[$id->toString()] = true;
        }

        return $cast;
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
