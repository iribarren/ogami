<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Persistence\Doctrine;

use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\InvalidCharacter;
use App\Play\Domain\Campaign\Hook;
use App\Play\Domain\Campaign\InvalidSceneCast;
use App\Play\Domain\Campaign\InvalidSceneKind;
use App\Play\Domain\Campaign\InvalidSessionParty;
use App\Play\Domain\Campaign\Lists\InvalidNpc;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Scene;
use App\Play\Domain\Campaign\SceneKind;
use App\Play\Domain\Campaign\Session;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\JsonType;

/**
 * Maps a campaign's sessions, with their scenes, to one JSON column (JSONB with the "jsonb"
 * option). Sessions and scenes are immutable values identified by their number within the
 * campaign, always loaded and saved with it, so they need no table of their own (ADR 0007).
 *
 * Stored shape: [{number, startedAt, scenes: [{number, title, startedAt, kind, sceneType, hook, cast, characters}], endedAt, party}],
 * in number order; `kind` is "scene" or "hook", `sceneType` may be null, and `hook` is set on a hook
 * scene and null on a scene of play. A scene stored before scenes had a kind has none of the last
 * three and reads as a scene of play without a Scene Type. `cast` is the list of the NPC ids in the
 * scene, in order, without repeats; a scene stored before scenes had a cast has none and reads
 * with an empty one. `characters` is the list of the Character ids in the scene and `party` the list
 * of the Character ids playing the session, each in order without repeats; one stored before
 * Characters joined scenes and sessions has none and reads with an empty list. `endedAt` is null while the session is under way; a session stored before
 * sessions ended has none and is under way. Times keep their microseconds and UTC offset. Reading
 * checks the shape, not the domain rules (Session and Scene
 * are rebuilt with reconstitute()).
 */
final class CampaignSessionsType extends JsonType
{
    public const string NAME = 'play_campaign_sessions';

    private const string TIME_FORMAT = 'Y-m-d\TH:i:s.uP';

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!\is_array($value)) {
            throw InvalidType::new($value, self::NAME, ['array', 'null']);
        }

        $sessions = [];
        foreach ($value as $session) {
            if (!$session instanceof Session) {
                throw InvalidType::new($session, self::NAME, [Session::class]);
            }

            $sessions[] = [
                'number' => $session->number(),
                'startedAt' => $session->startedAt()->format(self::TIME_FORMAT),
                'scenes' => array_map(
                    static fn (Scene $scene): array => [
                        'number' => $scene->number(),
                        'title' => $scene->title(),
                        'startedAt' => $scene->startedAt()->format(self::TIME_FORMAT),
                        'kind' => $scene->kind()->value,
                        'sceneType' => $scene->sceneType(),
                        'hook' => $scene->hook()?->value,
                        'cast' => array_map(static fn (NpcId $id): string => $id->toString(), $scene->cast()),
                        'characters' => array_map(static fn (CharacterId $id): string => $id->toString(), $scene->characters()),
                        'defaultTitle' => $scene->hasDefaultTitle(),
                    ],
                    $session->scenes(),
                ),
                'endedAt' => $session->endedAt()?->format(self::TIME_FORMAT),
                'party' => array_map(static fn (CharacterId $id): string => $id->toString(), $session->party()),
            ];
        }

        return parent::convertToDatabaseValue($sessions, $platform);
    }

    /**
     * @return list<Session>|null
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?array
    {
        $data = parent::convertToPHPValue($value, $platform);
        if (null === $data) {
            return null;
        }

        return array_map($this->session(...), $this->list($data, 'sessions'));
    }

    /**
     * A party that lists a Character twice is malformed (Session::reconstitute() checks it).
     */
    private function session(mixed $session): Session
    {
        try {
            return Session::reconstitute(
                $this->int($session, 'number'),
                $this->time($session, 'startedAt'),
                array_map(
                    $this->scene(...),
                    $this->list($this->field($session, 'scenes'), 'scenes'),
                ),
                null === $this->field($session, 'endedAt') ? null : $this->time($session, 'endedAt'),
                $this->characterIds($session, 'party'),
            );
        } catch (InvalidSessionParty $exception) {
            throw self::malformed('party', 'a list of distinct Character ids ('.$exception->getMessage().')');
        }
    }

    /**
     * A hook Scene names its hook; a scene of play has none (Scene::reconstitute() checks it).
     */
    private function scene(mixed $scene): Scene
    {
        $kind = $this->enum(SceneKind::class, $scene, 'kind') ?? SceneKind::Scene;

        try {
            return Scene::reconstitute(
                $this->int($scene, 'number'),
                $this->string($scene, 'title'),
                $this->time($scene, 'startedAt'),
                $kind,
                $this->optionalString($scene, 'sceneType'),
                $this->enum(Hook::class, $scene, 'hook'),
                $this->cast($scene),
                $this->characterIds($scene, 'characters'),
                $this->optionalBool($scene, 'defaultTitle'),
            );
        } catch (InvalidSceneKind) {
            throw self::malformed('hook', SceneKind::Hook === $kind ? 'set on a hook scene' : 'null on a scene of play');
        } catch (InvalidSceneCast $exception) {
            throw 'characters' === $exception->field ? self::malformed('characters', 'a list of distinct Character ids ('.$exception->getMessage().')') : self::malformed('cast', 'a list of distinct NPC ids ('.$exception->getMessage().')');
        }
    }

    /**
     * The NPC ids of a scene's cast; a scene stored before scenes had a cast has none. Whether the
     * NPCs exist is not checked here, as for the other references the sessions hold.
     *
     * @return list<NpcId>
     */
    private function cast(mixed $scene): array
    {
        $ids = $this->field($scene, 'cast');
        if (null === $ids) {
            return [];
        }

        try {
            return array_map(
                static fn (mixed $id): NpcId => \is_string($id) ? NpcId::fromString($id) : throw self::malformed('cast', 'a list of NPC id strings'),
                $this->list($ids, 'cast'),
            );
        } catch (InvalidNpc) {
            throw self::malformed('cast', 'a list of non-blank NPC ids');
        }
    }

    /**
     * The Character ids a scene or a session lists under this field; stored before Characters
     * joined them, it has none. Whether the Characters exist is not checked here, as for the NPCs.
     *
     * @return list<CharacterId>
     */
    private function characterIds(mixed $data, string $field): array
    {
        $ids = $this->field($data, $field);
        if (null === $ids) {
            return [];
        }

        try {
            return array_map(
                static fn (mixed $id): CharacterId => \is_string($id) ? CharacterId::fromString($id) : throw self::malformed($field, 'a list of Character id strings'),
                $this->list($ids, $field),
            );
        } catch (InvalidCharacter) {
            throw self::malformed($field, 'a list of non-blank Character ids');
        }
    }

    private function field(mixed $data, string $field): mixed
    {
        return \is_array($data) ? ($data[$field] ?? null) : null;
    }

    /**
     * @return list<mixed>
     */
    private function list(mixed $value, string $field): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw self::malformed($field, 'a list');
        }

        return $value;
    }

    private function int(mixed $data, string $field): int
    {
        $value = $this->field($data, $field);
        if (!\is_int($value)) {
            throw self::malformed($field, 'an integer');
        }

        return $value;
    }

    private function string(mixed $data, string $field): string
    {
        $value = $this->field($data, $field);
        if (!\is_string($value)) {
            throw self::malformed($field, 'a string');
        }

        return $value;
    }

    private function optionalString(mixed $data, string $field): ?string
    {
        return null === $this->field($data, $field) ? null : $this->string($data, $field);
    }

    /**
     * A scene stored before it recorded whether its title is the default has none.
     */
    private function optionalBool(mixed $data, string $field): ?bool
    {
        $value = $this->field($data, $field);
        if (null !== $value && !\is_bool($value)) {
            throw self::malformed($field, 'a boolean or null');
        }

        return $value;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return ?T null when the field is absent or null
     */
    private function enum(string $enum, mixed $data, string $field): ?\BackedEnum
    {
        $value = $this->optionalString($data, $field);

        return null === $value ? null : ($enum::tryFrom($value) ?? throw self::malformed($field, 'one of '.implode(', ', array_map(static fn (\BackedEnum $case): string => '"'.$case->value.'"', $enum::cases()))));
    }

    private function time(mixed $data, string $field): \DateTimeImmutable
    {
        $time = \DateTimeImmutable::createFromFormat(self::TIME_FORMAT, $this->string($data, $field));
        if (false === $time) {
            throw self::malformed($field, 'a time like 2026-10-06T10:00:00.000000+00:00');
        }

        return $time;
    }

    private static function malformed(string $field, string $expected): ValueNotConvertible
    {
        return ValueNotConvertible::new($field, self::NAME, \sprintf('Stored campaign sessions: "%s" must be %s.', $field, $expected));
    }
}
