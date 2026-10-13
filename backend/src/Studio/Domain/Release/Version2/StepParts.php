<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release\Version2;

use App\Formula\Domain\Formula;
use App\Formula\Domain\InvalidFormula;
use App\Formula\Domain\ReferenceKind;

/**
 * Walks the parts of canonical schema version 2 steps, with their paths: the outcomes that may
 * carry "next" and "effects" (the step itself, its bands, branches, "otherwise", options and check outcomes), the
 * effects, tracker references (a condition counting NPCs or Threads has none), placeholder texts
 * and rolled tables.
 *
 * @internal
 */
final class StepParts
{
    /**
     * The step lists of a Scene Type or a phase, by name, with their paths.
     *
     * @param array<string, mixed> $owner
     * @param list<string>         $names
     *
     * @return iterable<string, list<array<string, mixed>>> path => steps
     */
    public static function lists(array $owner, string $path, array $names): iterable
    {
        foreach ($names as $name) {
            /** @var list<array<string, mixed>> $steps */
            $steps = $owner[$name] ?? [];
            yield $path.'.'.$name => $steps;
        }
    }

    /**
     * @param array<string, mixed> $step
     *
     * @return iterable<string, array<string, mixed>> path => outcome
     */
    public static function outcomes(array $step, string $path): iterable
    {
        yield $path => $step;

        foreach (['bands', 'options', 'outcomes'] as $name) {
            foreach (self::objects($step[$name] ?? []) as $index => $outcome) {
                yield \sprintf('%s.%s[%d]', $path, $name, $index) => $outcome;
            }
        }

        $branches = self::objects($step['branches'] ?? []);
        foreach ($branches as $name => $outcome) {
            yield \is_int($name) ? \sprintf('%s.branches[%d]', $path, $name) : \sprintf('%s.branches.%s', $path, $name) => $outcome;
        }

        if (isset($step['otherwise'])) {
            /** @var array<string, mixed> $otherwise */
            $otherwise = $step['otherwise'];
            yield $path.'.otherwise' => $otherwise;
        }
    }

    /**
     * @param array<string, mixed> $step
     *
     * @return iterable<string, string> path => target key
     */
    public static function nexts(array $step, string $path): iterable
    {
        foreach (self::outcomes($step, $path) as $outcomePath => $outcome) {
            if (isset($outcome['next']) && \is_string($outcome['next'])) {
                yield $outcomePath.'.next' => $outcome['next'];
            }
        }
    }

    /**
     * @param array<string, mixed> $step
     *
     * @return iterable<string, array<string, mixed>> path => effect
     */
    public static function effects(array $step, string $path): iterable
    {
        foreach (self::outcomes($step, $path) as $outcomePath => $outcome) {
            yield from self::effectList($outcome, $outcomePath);
        }
    }

    /**
     * The effects of anything holding an "effects" list (an outcome or an oracle table entry).
     *
     * @param array<string, mixed> $holder
     *
     * @return iterable<string, array<string, mixed>> path => effect
     */
    public static function effectList(array $holder, string $path): iterable
    {
        foreach (self::objects($holder['effects'] ?? []) as $index => $effect) {
            yield \sprintf('%s.effects[%d]', $path, $index) => $effect;
        }
    }

    /**
     * @param array<string, mixed> $effect
     *
     * @return iterable<string, string> path => tracker key
     */
    public static function effectTrackers(array $effect, string $path): iterable
    {
        if ('tracker' !== ($effect['kind'] ?? null)) {
            return;
        }

        yield $path.'.tracker' => self::string($effect['tracker'] ?? null);
        if (\is_array($effect['value'] ?? null)) {
            yield from self::referencedTrackers($effect['value'], $path.'.value');
        }
    }

    /**
     * The Scene Type a nextScene or switchSceneType effect names, or null.
     *
     * @param array<string, mixed> $effect
     */
    public static function effectSceneType(array $effect): ?string
    {
        return \in_array($effect['kind'] ?? null, ['nextScene', 'switchSceneType'], true) ? self::string($effect['sceneType'] ?? null) : null;
    }

    /**
     * @param array<string, mixed> $step
     *
     * @return iterable<string, string> path => tracker key
     */
    public static function trackers(array $step, string $path): iterable
    {
        if ('condition' === ($step['kind'] ?? null) && isset($step['tracker'])) {
            yield $path.'.tracker' => self::string($step['tracker']);
        }

        foreach (self::objects($step['bands'] ?? []) as $index => $band) {
            if (\is_array($band['upTo'] ?? null)) {
                yield from self::referencedTrackers($band['upTo'], \sprintf('%s.bands[%d].upTo', $path, $index));
            }
        }

        foreach (self::effects($step, $path) as $effectPath => $effect) {
            yield from self::effectTrackers($effect, $effectPath);
        }
    }

    /**
     * Texts that may hold placeholders: path => [text, whether it is effect text].
     *
     * @param array<string, mixed> $step
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function texts(array $step, string $path): iterable
    {
        foreach (['title', 'prompt', 'tip'] as $name) {
            if (isset($step[$name]) && \is_string($step[$name])) {
                yield $path.'.'.$name => [$step[$name], false];
            }
        }

        foreach (self::effects($step, $path) as $effectPath => $effect) {
            yield from self::effectTexts($effect, $effectPath);
        }
    }

    /**
     * @param array<string, mixed> $effect
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function effectTexts(array $effect, string $path): iterable
    {
        if ('sceneTitle' === ($effect['kind'] ?? null)) {
            yield $path.'.title' => [self::string($effect['title'] ?? null), true];
        }

        if (\in_array($effect['kind'] ?? null, ['createNpc', 'createThread'], true)) {
            foreach (['name', 'agenda'] as $name) {
                if (isset($effect[$name]) && \is_string($effect[$name])) {
                    yield $path.'.'.$name => [$effect[$name], true];
                }
            }
        }

        if ('fillFact' === ($effect['kind'] ?? null) && isset($effect['text']) && \is_string($effect['text'])) {
            yield $path.'.text' => [$effect['text'], true];
        }
    }

    /**
     * The oracle table a "table" step rolls, or null.
     *
     * @param array<string, mixed> $step
     */
    public static function rolledTable(array $step): ?string
    {
        return 'table' === ($step['kind'] ?? null) ? self::string($step['table'] ?? null) : null;
    }

    /**
     * The objects of a canonical list or map; an empty \stdClass band has no part to walk.
     *
     * @return array<array-key, array<string, mixed>>
     */
    private static function objects(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        /** @var array<array-key, array<string, mixed>> $objects */
        $objects = array_filter($value, \is_array(...));

        return $objects;
    }

    /**
     * The trackers a canonical {tracker: key} or {formula: string} reads.
     *
     * @param array<array-key, mixed> $reference
     *
     * @return iterable<string, string> path => tracker key
     */
    private static function referencedTrackers(array $reference, string $path): iterable
    {
        if (!\array_key_exists('formula', $reference)) {
            yield $path.'.tracker' => self::string($reference['tracker'] ?? null);

            return;
        }

        try {
            $reads = Formula::fromString(self::string($reference['formula']))->references();
        } catch (InvalidFormula) {
            // Formulas reports the syntax error itself; a formula that does not parse reads nothing.
            return;
        }

        foreach ($reads as $read) {
            if (ReferenceKind::Tracker === $read->kind) {
                yield \sprintf('%s.formula[tracker:%s]', $path, $read->key) => $read->key;
            }
        }
    }

    private static function string(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}
