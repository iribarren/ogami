<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Lists\NpcId;

/**
 * A unit of story within a session. Numbered from 1 within its session; its identity is the
 * campaign, the session number and its own number.
 *
 * Its kind is `scene` (play, with an optional Scene Type key of the pinned release) or `hook`
 * (a phase boundary, with its hook name and no Scene Type). Only the title and the Scene Type of a
 * scene of play, and the cast of either kind, change after it starts, through withTitle(),
 * withSceneType(), withCast() and withCharacters(), which return a new Scene. The cast has two
 * parts, each an ordered list without repeats: the NPCs and the Characters in the scene.
 *
 * A scene also records whether its title is still the default it was created with ("Legwork 2"):
 * a Scene Type switch re-titles only such a title. Any rename clears it. A scene stored before the
 * record existed has none (null) and its title counts as default by its shape (see Campaign).
 */
final readonly class Scene
{
    public const int MAX_TITLE_LENGTH = 100;

    /**
     * @param list<NpcId>       $cast       the NPCs in the scene, in the order they joined
     * @param list<CharacterId> $characters the Characters in the scene, in the order they joined
     */
    private function __construct(
        private int $number,
        private string $title,
        private \DateTimeImmutable $startedAt,
        private SceneKind $kind,
        private ?string $sceneType,
        private ?Hook $hook,
        private array $cast = [],
        private array $characters = [],
        private ?bool $defaultTitle = false,
    ) {
    }

    /**
     * Starts a scene of play.
     *
     * @param ?string           $sceneType    the key of a Scene Type of the pinned release, or null
     * @param list<CharacterId> $characters   the Characters it starts with: the session's party
     * @param bool              $defaultTitle whether the title is the generated default of its Scene Type
     *
     * @throws InvalidSceneTitle when the trimmed title is blank or longer than 100 characters
     * @throws InvalidSceneCast  when the Characters list one twice
     */
    public static function start(int $number, string $title, \DateTimeImmutable $startedAt, ?string $sceneType = null, array $characters = [], bool $defaultTitle = false): self
    {
        return new self($number, self::checkedTitle($title), $startedAt, SceneKind::Scene, $sceneType, null, [], self::checkedCharacters($characters), $defaultTitle);
    }

    /**
     * Starts a hook Scene for a phase boundary.
     *
     * @param list<CharacterId> $characters the Characters it starts with: the session's party
     *
     * @throws InvalidSceneTitle when the trimmed title is blank or longer than 100 characters
     * @throws InvalidSceneCast  when the Characters list one twice
     */
    public static function startHook(int $number, Hook $hook, string $title, \DateTimeImmutable $startedAt, array $characters = []): self
    {
        return new self($number, self::checkedTitle($title), $startedAt, SceneKind::Hook, null, $hook, [], self::checkedCharacters($characters));
    }

    /**
     * Rebuilds a stored scene, e.g. from persistence. Only the kind is checked again: a hook Scene
     * names its hook and a scene of play has none, as the sessions reader requires. A scene stored
     * before scenes had a kind is a scene of play without a Scene Type; one stored before scenes had
     * a cast has an empty one, and so does one stored before it had Characters; one stored before the
     * default-title record has none (null).
     *
     * @param list<NpcId>       $cast         the NPCs in the scene, without repeats
     * @param list<CharacterId> $characters   the Characters in the scene, without repeats
     * @param ?bool             $defaultTitle whether the title is still its creation default; null when not recorded
     *
     * @throws InvalidSceneKind when the kind and the hook disagree
     * @throws InvalidSceneCast when the cast lists an NPC or a Character twice
     */
    public static function reconstitute(int $number, string $title, \DateTimeImmutable $startedAt, SceneKind $kind = SceneKind::Scene, ?string $sceneType = null, ?Hook $hook = null, array $cast = [], array $characters = [], ?bool $defaultTitle = null): self
    {
        if (SceneKind::Hook === $kind && !$hook instanceof Hook) {
            throw InvalidSceneKind::hookSceneWithoutHook();
        }

        if (SceneKind::Scene === $kind && $hook instanceof Hook) {
            throw InvalidSceneKind::sceneOfPlayWithHook($hook);
        }

        return new self($number, $title, $startedAt, $kind, $sceneType, $hook, self::checkedCast($cast), self::checkedCharacters($characters), $defaultTitle);
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

        return new self($this->number, $this->title, $this->startedAt, $this->kind, $sceneType, null, $this->cast, $this->characters, $this->defaultTitle);
    }

    /**
     * The same scene with another title; its number, kind, Scene Type and start stay. The title is
     * then a given one, not the default, whether the player or an Effect renamed the scene.
     *
     * @throws InvalidSceneTitle when the trimmed title is blank or longer than 100 characters
     */
    public function withTitle(string $title): self
    {
        return new self($this->number, self::checkedTitle($title), $this->startedAt, $this->kind, $this->sceneType, $this->hook, $this->cast, $this->characters, false);
    }

    /**
     * The same scene with other Characters in its cast; everything else stays.
     *
     * @param list<CharacterId> $characters
     *
     * @throws InvalidSceneCast when a Character is listed twice
     */
    public function withCharacters(array $characters): self
    {
        return new self($this->number, $this->title, $this->startedAt, $this->kind, $this->sceneType, $this->hook, $this->cast, self::checkedCharacters($characters), $this->defaultTitle);
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
        return new self($this->number, $this->title, $this->startedAt, $this->kind, $this->sceneType, $this->hook, self::checkedCast($cast), $this->characters, $this->defaultTitle);
    }

    public function number(): int
    {
        return $this->number;
    }

    public function title(): string
    {
        return $this->title;
    }

    /**
     * The same scene with a newly generated default title (a Scene Type switch of a default title).
     *
     * @throws InvalidSceneTitle when the trimmed title is blank or longer than 100 characters
     */
    public function withDefaultTitle(string $title): self
    {
        return new self($this->number, self::checkedTitle($title), $this->startedAt, $this->kind, $this->sceneType, $this->hook, $this->cast, $this->characters, true);
    }

    /**
     * Whether the title is still the default the scene was created with; null for a scene stored
     * before that was recorded, whose title only the shape can tell.
     */
    public function hasDefaultTitle(): ?bool
    {
        return $this->defaultTitle;
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
     * The Characters in the scene, in the order they joined, each once. A new scene starts with the
     * session's party; the player edits them with the NPCs (Campaign::editCurrentSceneCast()).
     *
     * @return list<CharacterId>
     */
    public function characters(): array
    {
        return $this->characters;
    }

    /**
     * @param list<CharacterId> $characters
     *
     * @return list<CharacterId>
     *
     * @throws InvalidSceneCast
     */
    private static function checkedCharacters(array $characters): array
    {
        $seen = [];
        foreach ($characters as $id) {
            if (isset($seen[$id->toString()])) {
                throw InvalidSceneCast::characterListedTwice($id);
            }

            $seen[$id->toString()] = true;
        }

        return $characters;
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
