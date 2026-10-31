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
    public const int MAX_PLACE_NAME_LENGTH = 200;
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
        'closeThread' => ['kind' => true, 'fact' => false],
        'createPlace' => ['kind' => true, 'name' => false, 'tags' => false],
        'fillFact' => ['kind' => true, 'slot' => true, 'text' => false],
        'sheet' => ['kind' => true, 'field' => true, 'op' => true, 'value' => false],
    ];

    private const array TRACKER_REF = ['tracker' => true];

    /** The sheet field types a sheet effect may change. */
    private const array CHANGEABLE_FIELDS = ['number', 'track', 'conditions'];

    /** The operations of a number or track field and those of a conditions field (ADR 0041). */
    private const array VALUE_OPS = ['add', 'subtract', 'set'];
    private const array CONDITION_OPS = ['mark', 'clear'];

    /** The kinds of effect a push cost holds (ADR 0041). */
    private const array PUSH_KINDS = ['sheet', 'tracker'];

    /**
     * @param bool $sheet whether the list sits where a Character is known (a check outcome, a character or prompt step, a choice option) and may hold sheet effects
     * @param bool $push  whether the list is the cost of a check's push: sheet and tracker effects only, and a mark may leave the option to the player
     *
     * @return list<array<string, mixed>> canonical effects; empty when absent
     */
    public static function list(mixed $value, string $path, Catalog $catalog, bool $sheet = false, bool $push = false): array
    {
        if (null === $value) {
            return [];
        }

        $effects = [];
        foreach (ReleaseFields::sizedList($value, $path, 0, self::MAX_EFFECTS, 'effects') as $index => $effect) {
            $effects[] = self::effect($effect, \sprintf('%s[%d]', $path, $index), $catalog, $sheet || $push, $push);
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
     * A list of at most MAX_TAGS unique keys, each a tag of the release that applies to $kind (the
     * kind of entity the step targets: npc, thread, place or character).
     *
     * @return list<string> the keys; empty when absent
     */
    public static function tags(mixed $value, string $path, Catalog $catalog, string $kind): array
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

            if (!$catalog->tagAppliesTo($key, $kind)) {
                throw InvalidReleaseContent::at($tagPath, \sprintf('tag "%s" does not apply to %ss', $key, $kind));
            }

            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * @return array<string, mixed>
     */
    private static function effect(mixed $value, string $path, Catalog $catalog, bool $sheet, bool $push): array
    {
        $kind = ReleaseFields::tag($value, $path, 'kind', array_keys(self::KINDS));
        if ($push && !\in_array($kind, self::PUSH_KINDS, true)) {
            throw InvalidReleaseContent::at($path.'.kind', \sprintf('a push cost allows sheet and tracker effects only, "%s" given', $kind));
        }

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

                $effect['tags'] = self::tags($effect['tags'] ?? null, $path.'.tags', $catalog, 'npc');
                break;
            case 'createThread':
                if (isset($effect['name'])) {
                    ReleaseFields::text($effect['name'], $path.'.name', 1, self::MAX_THREAD_NAME_LENGTH);
                }

                $effect['tags'] = self::tags($effect['tags'] ?? null, $path.'.tags', $catalog, 'thread');
                break;
            case 'createPlace':
                if (isset($effect['name'])) {
                    ReleaseFields::text($effect['name'], $path.'.name', 1, self::MAX_PLACE_NAME_LENGTH);
                }

                $effect['tags'] = self::tags($effect['tags'] ?? null, $path.'.tags', $catalog, 'place');
                break;
            case 'closeThread':
                if (isset($effect['fact'])) {
                    $effect['fact'] = self::factSlot($effect['fact'], $path.'.fact', $catalog);
                    $type = $catalog->factSlotType($effect['fact']);
                    if ('thread' !== $type) {
                        throw InvalidReleaseContent::at($path.'.fact', \sprintf('only allowed for a thread slot, fact slot "%s" is %s %s slot', $effect['fact'], 'npc' === $type ? 'an' : 'a', $type));
                    }
                }

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
                    throw InvalidReleaseContent::at($path, 'a sheet effect is allowed only in the outcomes of a check step, in the effects of a character or prompt step, in the effects of a choice option (not the skip option) and in the cost of a check push, never in a Scene Type\'s setup list (prompt steps and choice options there have no Character)');
                }

                $effect = self::sheetEffect($effect, $path, $catalog, $push);
                break;
        }

        // An empty "tags" list means the same as absent.
        return array_filter($effect, static fn (mixed $field): bool => [] !== $field);
    }

    /**
     * A sheet effect: add, subtract or set a number or track field, or mark or clear an option of
     * a conditions field (ADR 0041).
     *
     * @param array<string, mixed> $effect
     *
     * @return array<string, mixed>
     */
    private static function sheetEffect(array $effect, string $path, Catalog $catalog, bool $push): array
    {
        $effect['field'] = self::sheetField($effect['field'], $path.'.field', $catalog);
        $op = ReleaseFields::oneOf($effect['op'], $path.'.op', [...self::VALUE_OPS, ...self::CONDITION_OPS]);
        $conditions = 'conditions' === $catalog->fieldType($effect['field']);
        if ($conditions && \in_array($op, self::VALUE_OPS, true)) {
            throw InvalidReleaseContent::at($path.'.op', \sprintf('field "%s" is a conditions field, add, subtract and set change number and track fields only', $effect['field']));
        }

        if (!$conditions && \in_array($op, self::CONDITION_OPS, true)) {
            throw InvalidReleaseContent::at($path.'.op', \sprintf('field "%s" is a %s field, mark and clear change conditions fields only', $effect['field'], $catalog->fieldType($effect['field'])));
        }

        if (!$conditions) {
            if (!isset($effect['value'])) {
                throw InvalidReleaseContent::at($path.'.value', 'required');
            }

            $effect['value'] = self::sheetValue($effect['value'], $path.'.value', $catalog);

            return $effect;
        }

        if (!isset($effect['value'])) {
            if ('mark' === $op && !$push) {
                throw InvalidReleaseContent::at($path.'.value', 'a mark without an option is allowed only in the cost of a push, where the player picks the option');
            }

            return $effect;
        }

        $option = ReleaseFields::key($effect['value'], $path.'.value');
        if (!$catalog->hasConditionOption($effect['field'], $option)) {
            throw InvalidReleaseContent::at($path.'.value', \sprintf('unknown option "%s" of conditions field "%s"', $option, $effect['field']));
        }

        return $effect;
    }

    /**
     * A field type with its article, for messages: "a list", "an inventory".
     */
    public static function typed(string $type): string
    {
        return (\in_array($type[0] ?? '', ['a', 'e', 'i', 'o', 'u'], true) ? 'an ' : 'a ').$type;
    }

    /**
     * The key of a number, track or conditions field of the sheet.
     */
    private static function sheetField(mixed $value, string $path, Catalog $catalog): string
    {
        $key = ReleaseFields::key($value, $path);
        $type = $catalog->fieldType($key) ?? throw InvalidReleaseContent::at($path, \sprintf('unknown sheet field "%s"', $key));
        if (!\in_array($type, self::CHANGEABLE_FIELDS, true)) {
            throw InvalidReleaseContent::at($path, \sprintf('field "%s" is %s field, a sheet effect changes number and track fields only (mark and clear change conditions fields)', $key, self::typed($type)));
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
