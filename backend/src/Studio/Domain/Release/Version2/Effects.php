<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release\Version2;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseFields;

/**
 * Effect lists of schema version 2: a tagged union on "kind" (ADR 0018, decision 1). Referenced
 * trackers and Scene Types must exist in the release.
 *
 * @internal
 */
final class Effects
{
    public const int MAX_EFFECTS = 10;
    public const int MAX_VALUE = 1000;
    public const int MAX_TITLE_LENGTH = 100;

    /** Fields of each kind, in schema order: name => required. */
    private const array KINDS = [
        'tracker' => ['kind' => true, 'tracker' => true, 'op' => true, 'value' => true],
        'nextScene' => ['kind' => true, 'sceneType' => true],
        'switchSceneType' => ['kind' => true, 'sceneType' => true],
        'endPhase' => ['kind' => true],
        'sceneTitle' => ['kind' => true, 'title' => true],
    ];

    private const array TRACKER_REF = ['tracker' => true];

    /**
     * @return list<array<string, mixed>> canonical effects; empty when absent
     */
    public static function list(mixed $value, string $path, Catalog $catalog): array
    {
        if (null === $value) {
            return [];
        }

        $effects = [];
        foreach (ReleaseFields::sizedList($value, $path, 0, self::MAX_EFFECTS, 'effects') as $index => $effect) {
            $effects[] = self::effect($effect, \sprintf('%s[%d]', $path, $index), $catalog);
        }

        return $effects;
    }

    /**
     * {tracker: key}: the current value of a tracker of the release.
     *
     * @return array{tracker: string}
     */
    public static function trackerRef(mixed $value, string $path, Catalog $catalog): array
    {
        $ref = ReleaseFields::object($value, $path, self::TRACKER_REF);

        return ['tracker' => self::tracker($ref['tracker'], $path.'.tracker', $catalog)];
    }

    public static function tracker(mixed $value, string $path, Catalog $catalog): string
    {
        $key = ReleaseFields::key($value, $path);
        if (!$catalog->hasTracker($key)) {
            throw InvalidReleaseContent::at($path, \sprintf('unknown tracker "%s"', $key));
        }

        return $key;
    }

    public static function sceneType(mixed $value, string $path, Catalog $catalog): string
    {
        $key = ReleaseFields::key($value, $path);
        if (!$catalog->hasSceneType($key)) {
            throw InvalidReleaseContent::at($path, \sprintf('unknown Scene Type "%s"', $key));
        }

        return $key;
    }

    /**
     * @return array<string, mixed>
     */
    private static function effect(mixed $value, string $path, Catalog $catalog): array
    {
        $kind = ReleaseFields::tag($value, $path, 'kind', array_keys(self::KINDS));
        $effect = ReleaseFields::object($value, $path, self::KINDS[$kind]);

        switch ($kind) {
            case 'tracker':
                $effect['tracker'] = self::tracker($effect['tracker'], $path.'.tracker', $catalog);
                ReleaseFields::oneOf($effect['op'], $path.'.op', ['add', 'set']);
                $effect['value'] = \is_int($effect['value'])
                    ? ReleaseFields::integer($effect['value'], $path.'.value', -self::MAX_VALUE, self::MAX_VALUE)
                    : self::valueRef($effect['value'], $path.'.value', $catalog);
                break;
            case 'nextScene':
            case 'switchSceneType':
                $effect['sceneType'] = self::sceneType($effect['sceneType'], $path.'.sceneType', $catalog);
                break;
            case 'sceneTitle':
                ReleaseFields::text($effect['title'], $path.'.title', 1, self::MAX_TITLE_LENGTH);
                break;
        }

        return $effect;
    }

    /**
     * @return array{tracker: string}
     */
    private static function valueRef(mixed $value, string $path, Catalog $catalog): array
    {
        if (!\is_array($value) || [] === $value || array_is_list($value)) {
            throw InvalidReleaseContent::at($path, 'must be an integer or {tracker: key}');
        }

        return self::trackerRef($value, $path, $catalog);
    }
}
