<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Persistence\Doctrine;

use App\Play\Domain\Campaign\Scene;
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
 * Stored shape: [{number, startedAt, scenes: [{number, title, startedAt}]}], in number order.
 * Times keep their microseconds and UTC offset. Reading checks the shape, not the domain rules
 * (Session and Scene are rebuilt with reconstitute()).
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
                    ],
                    $session->scenes(),
                ),
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

        return array_map(
            static fn (mixed $session): Session => Session::reconstitute(
                self::int($session, 'number'),
                self::time($session, 'startedAt'),
                array_map(
                    static fn (mixed $scene): Scene => Scene::reconstitute(
                        self::int($scene, 'number'),
                        self::string($scene, 'title'),
                        self::time($scene, 'startedAt'),
                    ),
                    self::list(self::field($session, 'scenes'), 'scenes'),
                ),
            ),
            self::list($data, 'sessions'),
        );
    }

    private static function field(mixed $data, string $field): mixed
    {
        return \is_array($data) ? ($data[$field] ?? null) : null;
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value, string $field): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw self::malformed($field, 'a list');
        }

        return $value;
    }

    private static function int(mixed $data, string $field): int
    {
        $value = self::field($data, $field);
        if (!\is_int($value)) {
            throw self::malformed($field, 'an integer');
        }

        return $value;
    }

    private static function string(mixed $data, string $field): string
    {
        $value = self::field($data, $field);
        if (!\is_string($value)) {
            throw self::malformed($field, 'a string');
        }

        return $value;
    }

    private static function time(mixed $data, string $field): \DateTimeImmutable
    {
        $time = \DateTimeImmutable::createFromFormat(self::TIME_FORMAT, self::string($data, $field));
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
