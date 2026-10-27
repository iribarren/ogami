<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release\Version2;

use App\Formula\Domain\Formula;
use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseFields;

/**
 * The checks of a schema version 2 release (ADR 0024): a dice expression or a pool (ADR 0041) that
 * counts the dice at or above a threshold, with optional critical pairs and Hunger dice (ADR 0044),
 * an optional formula modifier over the sheet (never with a pool), an optional push a pool check
 * may take once with its cost and cap, an optional difficulty the player sets at roll time, and outcome bands
 * whose bounds are integers, trackers or formulas over the sheet (plus {check:difficulty} when the
 * check declares a difficulty). Returns the canonical checks and what check steps may name of each.
 *
 * @phpstan-import-type CheckShape from Catalog
 *
 * @internal
 */
final class Checks
{
    public const int MAX_CHECKS = 50;
    public const int MAX_BANDS = 10;
    public const int MAX_LABEL_LENGTH = 100;
    public const int MAX_HINT_LENGTH = 500;
    public const int MAX_DIFFICULTY_LABEL_LENGTH = 60;
    public const int MAX_DIFFICULTY = 1000;
    public const int MAX_POOL_COUNT = 100;
    public const int MIN_POOL_SIDES = 2;
    public const int MAX_POOL_SIDES = 1000;
    public const int MAX_CRITICALS_BONUS = 10;

    /** The special results of a pool roll (ADR 0044), in contract order. */
    public const array SPECIALS = ['critical', 'messyCritical', 'bestialFailure'];

    /** A check step outcome names a band or a special result, each once. */
    public const int MAX_OUTCOMES = self::MAX_BANDS + 3;

    private const array CHECK = ['key' => true, 'label' => true, 'hint' => false, 'dice' => false, 'pool' => false, 'modifier' => false, 'difficulty' => false, 'push' => false, 'bands' => true];
    private const array POOL = ['count' => true, 'sides' => true, 'successAt' => true, 'criticals' => false, 'hunger' => false];
    private const array CRITICALS = ['bonus' => true];
    private const array HUNGER = ['count' => true];
    private const array PUSH = ['label' => true, 'effects' => false, 'maxDice' => false];
    private const array DIFFICULTY = ['label' => true, 'default' => true, 'min' => false, 'max' => false];
    private const array BAND = ['key' => true, 'label' => true, 'upTo' => false];
    private const array FORMULA_REF = ['formula' => true];

    /**
     * @param array<string, string> $fieldTypes the type of every field of the sheet by key
     * @param Catalog               $catalog    the release's keys, without checks
     *
     * @return array{list<array<string, mixed>>, array<string, CheckShape>} the canonical checks and their shapes by key
     */
    public static function validate(mixed $value, array $fieldTypes, Catalog $catalog): array
    {
        $checks = [];
        $shapes = [];
        foreach (ReleaseFields::sizedList($value, 'checks', 0, self::MAX_CHECKS, 'checks') as $index => $check) {
            $path = \sprintf('checks[%d]', $index);
            $check = ReleaseFields::object($check, $path, self::CHECK);
            $key = ReleaseFields::key($check['key'], $path.'.key');
            if (isset($shapes[$key])) {
                throw InvalidReleaseContent::at($path.'.key', \sprintf('duplicate check key "%s"', $key));
            }

            ReleaseFields::text($check['label'], $path.'.label', 1, self::MAX_LABEL_LENGTH);
            if (isset($check['hint'])) {
                ReleaseFields::text($check['hint'], $path.'.hint', 0, self::MAX_HINT_LENGTH);
            }

            $trackerKeys = array_keys($catalog->trackers);
            if (isset($check['dice']) && isset($check['pool'])) {
                throw InvalidReleaseContent::at($path, 'give either dice or pool, not both');
            }

            $pool = null;
            if (isset($check['pool'])) {
                $pool = self::pool($check['pool'], $path.'.pool', $fieldTypes, $trackerKeys);
                $check['pool'] = $pool;
                if (isset($check['modifier'])) {
                    throw InvalidReleaseContent::at($path.'.modifier', 'a pool check has no modifier, its count formula sizes the pool');
                }
            } elseif (isset($check['dice'])) {
                Steps::dice($check['dice'], $path.'.dice');
            } else {
                throw InvalidReleaseContent::at($path.'.dice', 'required unless the check declares a pool');
            }

            if (isset($check['push'])) {
                if (!isset($check['pool'])) {
                    throw InvalidReleaseContent::at($path.'.push', 'only a check with a pool can be pushed');
                }

                $check['push'] = self::push($check['push'], $path.'.push', $catalog);
            }

            if (isset($check['modifier'])) {
                $modifier = ReleaseFields::text($check['modifier'], $path.'.modifier', 1, Formula::MAX_LENGTH);
                Formulas::check($modifier, $path.'.modifier', $fieldTypes, $trackerKeys, null);
            }

            $difficulty = isset($check['difficulty']) ? self::difficulty($check['difficulty'], $path.'.difficulty') : null;
            if (null !== $difficulty) {
                $check['difficulty'] = $difficulty;
            }

            [$check['bands'], $bandKeys] = self::bands($check['bands'], $path.'.bands', $fieldTypes, $trackerKeys, null !== $difficulty, $catalog);

            $checks[] = $check;
            $shapes[$key] = [
                'difficulty' => null === $difficulty ? null : ['min' => $difficulty['min'] ?? null, 'max' => $difficulty['max'] ?? null],
                'bands' => $bandKeys,
                'specials' => self::specials($pool),
            ];
        }

        return [$checks, $shapes];
    }

    /**
     * {count, sides, successAt, criticals?, hunger?}: the count is an integer or a {formula} over
     * the sheet, and a die succeeds when its face is at least successAt. Critical pairs add their
     * bonus, and Hunger dice are counted like the pool's own count (ADR 0044).
     *
     * @param array<string, string> $fieldTypes
     * @param list<string>          $trackerKeys
     *
     * @return array{count: int|array{formula: string}, sides: int, successAt: int, criticals?: array{bonus: int}, hunger?: array{count: int|array{formula: string}}}
     */
    private static function pool(mixed $value, string $path, array $fieldTypes, array $trackerKeys): array
    {
        $pool = ReleaseFields::object($value, $path, self::POOL);
        $count = self::count($pool['count'], $path.'.count', 1, $fieldTypes, $trackerKeys);
        $sides = ReleaseFields::integer($pool['sides'], $path.'.sides', self::MIN_POOL_SIDES, self::MAX_POOL_SIDES);
        $successAt = ReleaseFields::integer($pool['successAt'], $path.'.successAt', 1, self::MAX_POOL_SIDES);
        if ($successAt > $sides) {
            throw InvalidReleaseContent::at($path.'.successAt', \sprintf('must be at most sides (%d), %d given', $sides, $successAt));
        }

        $checked = ['count' => $count, 'sides' => $sides, 'successAt' => $successAt];
        if (isset($pool['criticals'])) {
            $criticals = ReleaseFields::object($pool['criticals'], $path.'.criticals', self::CRITICALS);
            $checked['criticals'] = ['bonus' => ReleaseFields::integer($criticals['bonus'], $path.'.criticals.bonus', 1, self::MAX_CRITICALS_BONUS)];
        }

        if (isset($pool['hunger'])) {
            $hunger = ReleaseFields::object($pool['hunger'], $path.'.hunger', self::HUNGER);
            // Zero Hunger dice is a valid count: Play clamps any count to the size of the pool.
            $checked['hunger'] = ['count' => self::count($hunger['count'], $path.'.hunger.count', 0, $fieldTypes, $trackerKeys)];
        }

        return $checked;
    }

    /**
     * A pool or Hunger count: an integer from $min to 100, or a {formula} over the sheet read like a modifier.
     *
     * @param array<string, string> $fieldTypes
     * @param list<string>          $trackerKeys
     *
     * @return int|array{formula: string}
     */
    private static function count(mixed $count, string $path, int $min, array $fieldTypes, array $trackerKeys): int|array
    {
        if (\is_array($count) && [] !== $count && !array_is_list($count)) {
            $ref = ReleaseFields::object($count, $path, self::FORMULA_REF);
            $formula = ReleaseFields::text($ref['formula'], $path.'.formula', 1, Formula::MAX_LENGTH);
            Formulas::check($formula, $path.'.formula', $fieldTypes, $trackerKeys, null);

            return ['formula' => $formula];
        }

        if (\is_int($count)) {
            return ReleaseFields::integer($count, $path, $min, self::MAX_POOL_COUNT);
        }

        throw InvalidReleaseContent::at($path, 'must be an integer or {formula: string}');
    }

    /**
     * The special results a check can produce (ADR 0044): a critical needs critical pairs, a bestial
     * failure Hunger dice, and a messy critical both. A dice check produces none. A literal Hunger
     * count of 0 rolls no Hunger dice, so it enables none of the Hunger results (a formula might).
     *
     * @param array<string, mixed>|null $pool the canonical pool, or null for a dice check
     *
     * @return array<string, true>
     */
    private static function specials(?array $pool): array
    {
        $criticals = isset($pool['criticals']);
        $hunger = isset($pool['hunger']) && 0 !== $pool['hunger']['count'];

        return array_filter(['critical' => $criticals, 'messyCritical' => $criticals && $hunger, 'bestialFailure' => $hunger]);
    }

    /**
     * {label, effects?, maxDice?}: the cost of the push, applied by sheet and tracker effects
     * (ADR 0041), and the most failed regular dice it re-rolls (ADR 0044).
     *
     * @return array{label: string, effects?: list<array<string, mixed>>, maxDice?: int}
     */
    private static function push(mixed $value, string $path, Catalog $catalog): array
    {
        $push = ReleaseFields::object($value, $path, self::PUSH);
        $checked = ['label' => ReleaseFields::text($push['label'], $path.'.label', 1, self::MAX_LABEL_LENGTH)];
        $effects = Effects::list($push['effects'] ?? null, $path.'.effects', $catalog, push: true);
        if ([] !== $effects) {
            $checked['effects'] = $effects;
        }

        if (isset($push['maxDice'])) {
            $checked['maxDice'] = ReleaseFields::integer($push['maxDice'], $path.'.maxDice', 1, self::MAX_POOL_COUNT);
        }

        return $checked;
    }

    /**
     * @return array{label: string, default: int, min?: int, max?: int}
     */
    private static function difficulty(mixed $value, string $path): array
    {
        $difficulty = ReleaseFields::object($value, $path, self::DIFFICULTY);
        $checked = [
            'label' => ReleaseFields::text($difficulty['label'], $path.'.label', 1, self::MAX_DIFFICULTY_LABEL_LENGTH),
            'default' => ReleaseFields::integer($difficulty['default'], $path.'.default', -self::MAX_DIFFICULTY, self::MAX_DIFFICULTY),
        ];
        foreach (['min', 'max'] as $name) {
            if (isset($difficulty[$name])) {
                $checked[$name] = ReleaseFields::integer($difficulty[$name], $path.'.'.$name, -self::MAX_DIFFICULTY, self::MAX_DIFFICULTY);
            }
        }

        if (isset($checked['min']) && $checked['min'] > $checked['default']) {
            throw InvalidReleaseContent::at($path.'.min', \sprintf('must be at most default (%d), %d given', $checked['default'], $checked['min']));
        }

        if (isset($checked['max']) && $checked['max'] < $checked['default']) {
            throw InvalidReleaseContent::at($path.'.max', \sprintf('must be at least default (%d), %d given', $checked['default'], $checked['max']));
        }

        return $checked;
    }

    /**
     * @param array<string, string> $fieldTypes
     * @param list<string>          $trackerKeys
     *
     * @return array{list<array<string, mixed>>, array<string, true>} the canonical bands and their keys
     */
    private static function bands(mixed $value, string $path, array $fieldTypes, array $trackerKeys, bool $difficulty, Catalog $catalog): array
    {
        $bands = [];
        $keys = [];
        foreach (ReleaseFields::sizedList($value, $path, 1, self::MAX_BANDS, 'bands') as $index => $band) {
            $bandPath = \sprintf('%s[%d]', $path, $index);
            $band = ReleaseFields::object($band, $bandPath, self::BAND);
            $key = ReleaseFields::key($band['key'], $bandPath.'.key');
            if (isset($keys[$key])) {
                throw InvalidReleaseContent::at($bandPath.'.key', \sprintf('duplicate band key "%s"', $key));
            }

            $keys[$key] = true;
            ReleaseFields::text($band['label'], $bandPath.'.label', 1, self::MAX_LABEL_LENGTH);
            if (isset($band['upTo']) && !\is_int($band['upTo'])) {
                $band['upTo'] = self::bound($band['upTo'], $bandPath.'.upTo', $fieldTypes, $trackerKeys, $difficulty, $catalog);
            }

            $bands[] = $band;
        }

        // Only the literal bounds can be ordered; the rest is left out, as in the bands of a roll.
        Bands::order($bands, $path, 'band');

        return [$bands, $keys];
    }

    /**
     * {tracker: key} or {formula: string} over the sheet.
     *
     * @param array<string, string> $fieldTypes
     * @param list<string>          $trackerKeys
     *
     * @return array{tracker: string}|array{formula: string}
     */
    private static function bound(mixed $value, string $path, array $fieldTypes, array $trackerKeys, bool $difficulty, Catalog $catalog): array
    {
        if (!\is_array($value) || [] === $value || array_is_list($value)) {
            throw InvalidReleaseContent::at($path, 'must be an integer, {tracker: key} or {formula: string}');
        }

        if (!\array_key_exists('formula', $value)) {
            return Effects::trackerRef($value, $path, $catalog);
        }

        $ref = ReleaseFields::object($value, $path, self::FORMULA_REF);
        $formula = ReleaseFields::text($ref['formula'], $path.'.formula', 1, Formula::MAX_LENGTH);
        Formulas::check($formula, $path.'.formula', $fieldTypes, $trackerKeys, $difficulty);

        return ['formula' => $formula];
    }
}
