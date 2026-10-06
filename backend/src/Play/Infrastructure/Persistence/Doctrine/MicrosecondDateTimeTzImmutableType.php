<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeTzImmutableType;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Exception\InvalidType;

/**
 * A point in time with its UTC offset, kept to the microsecond: TIMESTAMP(6) WITH TIME ZONE.
 * DBAL's datetimetz_immutable declares TIMESTAMP(0) and writes whole seconds, so a reloaded
 * value would differ from the one the domain recorded.
 *
 * doctrine.yaml maps every introspected timestamptz column to this type, so the schema tool
 * compares like with like. Only Play stores TIMESTAMP WITH TIME ZONE columns today.
 */
final class MicrosecondDateTimeTzImmutableType extends DateTimeTzImmutableType
{
    public const string NAME = 'datetimetz_immutable_microseconds';

    private const string WRITE_FORMAT = 'Y-m-d H:i:s.uO';

    /** PostgreSQL leaves out a zero fraction: "2026-10-06 10:00:00+00". */
    private const array READ_FORMATS = ['Y-m-d H:i:s.uO', 'Y-m-d H:i:sO'];

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'TIMESTAMP(6) WITH TIME ZONE';
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof \DateTimeImmutable) {
            return $value->format(self::WRITE_FORMAT);
        }

        throw InvalidType::new($value, self::NAME, ['null', \DateTimeImmutable::class]);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if (null === $value || $value instanceof \DateTimeImmutable) {
            return $value;
        }

        if (\is_string($value)) {
            foreach (self::READ_FORMATS as $format) {
                $dateTime = \DateTimeImmutable::createFromFormat('!'.$format, $value);
                if (false !== $dateTime) {
                    return $dateTime;
                }
            }
        }

        throw InvalidFormat::new(\is_string($value) ? $value : get_debug_type($value), self::NAME, self::WRITE_FORMAT);
    }
}
