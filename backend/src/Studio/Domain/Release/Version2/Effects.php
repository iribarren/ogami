<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release\Version2;

use App\Formula\Domain\Formula;
use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseFields;

/**
 * Effect lists of schema version 2: a tagged union on "kind" (ADR 0018, decision 1). Referenced
 * trackers, Scene Types and tags must exist in the release.
 *
 * @internal
 */
final class Effects
{
    public const int MAX_EFFECTS = 10;
    public const int MAX_VALUE = 1000;
    public const int MAX_TITLE_LENGTH = 100;
    public const int MAX_TAGS = 5;
    public const int MAX_NPC_NAME_LENGTH = 100;
    public const int MAX_NPC_AGENDA_LENGTH = 2000;
    public const int MAX_THREAD_NAME_LENGTH = 200;
    public const int MAX_FACT_TEXT_LENGTH = 2000;

    /** Fields of each kind, in schema order: name => required. */
    private const array KINDS = [
        'tracker' => ['kind' => true, 'tracker' => true, 'op' => true, 'value' => true],
        'nextScene' => ['kind' => true, 'sceneType' => true],
        'switchSceneType' => ['kind' => true, 'sceneType' => true],
        'endPhase' => ['kind' => true],
        'sceneTitle' => ['kind' => true, 'title' => true],
        'createNpc' => ['kind' => true, 'name' => false, 'agenda' => false, 'tags' => false],
        'createThread' => ['kind' => true, 'name' => false, 'tags' => false],
        'closeThread' => ['kind' => true],
        'fillFact' => ['kind' => true, 'slot' => true, 'text' => false],
        'sheet' => ['kind' => true, 'field' => true, 'op' => true, 'value' => true],
    ];

    private const array TRACKER_REF = ['tracker' => true];

    /** The sheet field types a sheet effect may change. */
    private const array CHANGEABLE_FIELDS = ['number', 'track'];

    /**
     * @param bool $sheet whether the list sits where a Character is known (a check outcome, a character step) and may hold sheet effects
     *
     * @return list<array<string, mixed>> canonical effects; empty when absent
     */
    public static function list(mixed $value, string $path, Catalog $catalog, bool $sheet = false): array
    {
        if (null === $value) {
            return [];
        }

        $effects = [];
        foreach (ReleaseFields::sizedList($value, $path, 0, self::MAX_EFFECTS, 'effects') as $index => $effect) {
            $effects[] = self::effect($effect, \sprintf('%s[%d]', $path, $index), $catalog, $sheet);
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

    public static function factSlot(mixed $value, string $path, Catalog $catalog): string
    {
        $key = ReleaseFields::key($value, $path);
        if (!$catalog->hasFactSlot($key)) {
            throw InvalidReleaseContent::at($path, \sprintf('unknown fact slot "%s"', $key));
        }

        return $key;
    }

    /**
     * A list of at most MAX_TAGS unique keys, each a tag of the release.
     *
     * @return list<string> the keys; empty when absent
     */
    public static function tags(mixed $value, string $path, Catalog $catalog): array
    {
        if (null === $value) {
            return [];
        }

        $keys = [];
        foreach (ReleaseFields::sizedList($value, $path, 0, self::MAX_TAGS, 'tags') as $index => $tag) {
            $tagPath = \sprintf('%s[%d]', $path, $index);
            $key = ReleaseFields::key($tag, $tagPath);
            if (!$catalog->hasTag($key)) {
                throw InvalidReleaseContent::at($tagPath, \sprintf('unknown tag "%s"', $key));
            }

            if (\in_array($key, $keys, true)) {
                throw InvalidReleaseContent::at($tagPath, \sprintf('duplicate tag "%s"', $key));
            }

            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * @return array<string, mixed>
     */
    private static function effect(mixed $value, string $path, Catalog $catalog, bool $sheet): array
    {
        $kind = ReleaseFields::tag($value, $path, 'kind', array_keys(self::KINDS));
        $effect = ReleaseFields::object($value, $path, self::KINDS[$kind]);

        switch ($kind) {
            case 'tracker':
                $effect['tracker'] = self::tracker($effect['tracker'], $path.'.tracker', $catalog);
                ReleaseFields::oneOf($effect['op'], $path.'.op', ['add', 'set']);
                $effect['value'] = \is_int($effect['value'])
                    ? ReleaseFields::integer($effect['value'], $path.'.value', -self::MAX_VALUE, self::MAX_VALUE)
                    : self::reference($effect['value'], $path.'.value', $catalog);
                break;
            case 'nextScene':
            case 'switchSceneType':
                $effect['sceneType'] = self::sceneType($effect['sceneType'], $path.'.sceneType', $catalog);
                break;
            case 'sceneTitle':
                ReleaseFields::text($effect['title'], $path.'.title', 1, self::MAX_TITLE_LENGTH);
                break;
            case 'createNpc':
                if (isset($effect['name'])) {
                    ReleaseFields::text($effect['name'], $path.'.name', 1, self::MAX_NPC_NAME_LENGTH);
                }

                if (isset($effect['agenda'])) {
                    ReleaseFields::text($effect['agenda'], $path.'.agenda', 0, self::MAX_NPC_AGENDA_LENGTH);
                }

                $effect['tags'] = self::tags($effect['tags'] ?? null, $path.'.tags', $catalog);
                break;
            case 'createThread':
                if (isset($effect['name'])) {
                    ReleaseFields::text($effect['name'], $path.'.name', 1, self::MAX_THREAD_NAME_LENGTH);
                }

                $effect['tags'] = self::tags($effect['tags'] ?? null, $path.'.tags', $catalog);
                break;
            case 'fillFact':
                $effect['slot'] = self::factSlot($effect['slot'], $path.'.slot', $catalog);
                if (isset($effect['text'])) {
                    ReleaseFields::text($effect['text'], $path.'.text', 1, self::MAX_FACT_TEXT_LENGTH);
                    $type = $catalog->factSlotType($effect['slot']);
                    if ('text' !== $type) {
                        throw InvalidReleaseContent::at($path.'.text', \sprintf('only allowed for a text slot, fact slot "%s" is %s %s slot', $effect['slot'], 'npc' === $type ? 'an' : 'a', $type));
                    }
                }

                break;
            case 'sheet':
                if (!$sheet) {
                    throw InvalidReleaseContent::at($path, 'a sheet effect is allowed only in the outcomes of a check step and in the effects of a character step');
                }

                $effect['field'] = self::sheetField($effect['field'], $path.'.field', $catalog);
                ReleaseFields::oneOf($effect['op'], $path.'.op', ['add', 'subtract', 'set']);
                $effect['value'] = self::sheetValue($effect['value'], $path.'.value', $catalog);
                break;
        }

        // An empty "tags" list means the same as absent.
        return array_filter($effect, static fn (mixed $field): bool => [] !== $field);
    }

    /**
     * The key of a number or track field of the sheet.
     */
    private static function sheetField(mixed $value, string $path, Catalog $catalog): string
    {
        $key = ReleaseFields::key($value, $path);
        $type = $catalog->fieldType($key) ?? throw InvalidReleaseContent::at($path, \sprintf('unknown sheet field "%s"', $key));
        if (!\in_array($type, self::CHANGEABLE_FIELDS, true)) {
            throw InvalidReleaseContent::at($path, \sprintf('field "%s" is a %s field, a sheet effect changes number and track fields only', $key, $type));
        }

        return $key;
    }

    /**
     * The value of a sheet effect: an integer, a {formula} over the sheet or a {dice} expression.
     *
     * @return int|array{formula: string}|array{dice: string}
     */
    private static function sheetValue(mixed $value, string $path, Catalog $catalog): int|array
    {
        if (\is_int($value)) {
            return ReleaseFields::integer($value, $path, -self::MAX_VALUE, self::MAX_VALUE);
        }

        if (!\is_array($value) || [] === $value || array_is_list($value)) {
            throw InvalidReleaseContent::at($path, 'must be an integer, {formula: string} or {dice: string}');
        }

        if (\array_key_exists('formula', $value)) {
            $ref = ReleaseFields::object($value, $path, ['formula' => true]);
            $formulaPath = $path.'.formula';
            $text = ReleaseFields::text($ref['formula'], $formulaPath, 1, Formula::MAX_LENGTH);
            Formulas::check($text, $formulaPath, $catalog->fieldTypes, array_keys($catalog->trackers), null);

            return ['formula' => $text];
        }

        $ref = ReleaseFields::object($value, $path, ['dice' => true]);

        return ['dice' => Steps::dice($ref['dice'], $path.'.dice')];
    }

    /**
     * The object forms of a band "upTo" and a tracker effect "value": {tracker: key} or {formula: string}.
     *
     * @return array{tracker: string}|array{formula: string}
     */
    public static function reference(mixed $value, string $path, Catalog $catalog): array
    {
        if (!\is_array($value) || [] === $value || array_is_list($value)) {
            throw InvalidReleaseContent::at($path, 'must be an integer, {tracker: key} or {formula: string}');
        }

        return \array_key_exists('formula', $value)
            ? Formulas::flow($value, $path, $catalog)
            : self::trackerRef($value, $path, $catalog);
    }
}
