<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release\Version2;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseFields;

/**
 * Outcome bands (ADR 0018, decision 2) and counter levels, which follow the same ordering: every
 * band but the last has an "upTo" upper bound, the last omits it and catches the rest, and literal
 * "upTo" values strictly increase. Band "upTo" may also be a tracker's current value.
 *
 * @internal
 */
final class Bands
{
    public const int MAX_BANDS = 20;
    public const int MAX_LEVEL_LABEL_LENGTH = 100;

    private const array BAND = ['upTo' => false, 'next' => false, 'effects' => false];
    private const array LEVEL = ['upTo' => false, 'label' => true];

    /**
     * A band with no field is kept as an empty \stdClass, so the canonical JSON keeps it an object.
     *
     * @return list<array<string, mixed>|\stdClass> canonical bands; empty when absent
     */
    public static function outcomes(mixed $value, string $path, int $min, Catalog $catalog): array
    {
        $bands = [];
        foreach (self::sized($value, $path, $min, 'bands') as $index => $band) {
            $bandPath = \sprintf('%s[%d]', $path, $index);
            $band = ReleaseFields::object($band, $bandPath, self::BAND);
            if (isset($band['upTo'])) {
                $band['upTo'] = \is_array($band['upTo']) && [] !== $band['upTo'] && !array_is_list($band['upTo'])
                    ? Effects::trackerRef($band['upTo'], $bandPath.'.upTo', $catalog)
                    : self::literal($band['upTo'], $bandPath.'.upTo', 'must be an integer or {tracker: key}');
            }

            if (isset($band['next'])) {
                ReleaseFields::key($band['next'], $bandPath.'.next');
            }

            $band['effects'] = Effects::list($band['effects'] ?? null, $bandPath.'.effects', $catalog);
            $band = array_filter($band, static fn (mixed $field): bool => [] !== $field);
            $bands[] = $band;
        }

        self::order($bands, $path, 'band');

        return array_map(static fn (array $band): array|\stdClass => [] === $band ? new \stdClass() : $band, $bands);
    }

    /**
     * Counter levels: literal "upTo" only.
     *
     * @return list<array<string, mixed>> canonical levels; empty when absent
     */
    public static function levels(mixed $value, string $path): array
    {
        $levels = [];
        foreach (self::sized($value, $path, 0, 'levels') as $index => $level) {
            $levelPath = \sprintf('%s[%d]', $path, $index);
            $level = ReleaseFields::object($level, $levelPath, self::LEVEL);
            if (isset($level['upTo'])) {
                self::literal($level['upTo'], $levelPath.'.upTo', 'must be an integer');
            }

            ReleaseFields::text($level['label'], $levelPath.'.label', 1, self::MAX_LEVEL_LABEL_LENGTH);
            $levels[] = $level;
        }

        self::order($levels, $path, 'level');

        return $levels;
    }

    /**
     * @return list<mixed>
     */
    private static function sized(mixed $value, string $path, int $min, string $noun): array
    {
        return null === $value && 0 === $min ? [] : ReleaseFields::sizedList($value, $path, $min, self::MAX_BANDS, $noun);
    }

    private static function literal(mixed $value, string $path, string $message): int
    {
        if (!\is_int($value)) {
            throw InvalidReleaseContent::at($path, $message);
        }

        return $value;
    }

    /**
     * @param list<array<string, mixed>> $bands
     */
    private static function order(array $bands, string $path, string $noun): void
    {
        $last = \count($bands) - 1;
        $previous = null;
        foreach ($bands as $index => $band) {
            $upToPath = \sprintf('%s[%d].upTo', $path, $index);
            if ($index === $last) {
                if (isset($band['upTo'])) {
                    throw InvalidReleaseContent::at($upToPath, \sprintf('the last %s catches the rest and must omit upTo', $noun));
                }

                continue;
            }

            if (!isset($band['upTo'])) {
                throw InvalidReleaseContent::at($upToPath, \sprintf('required, only the last %s omits it', $noun));
            }

            if (!\is_int($band['upTo'])) {
                continue;
            }

            if (null !== $previous && $band['upTo'] <= $previous) {
                throw InvalidReleaseContent::at($upToPath, \sprintf('must be greater than %d, the previous upTo, %d given', $previous, $band['upTo']));
            }

            $previous = $band['upTo'];
        }
    }
}
