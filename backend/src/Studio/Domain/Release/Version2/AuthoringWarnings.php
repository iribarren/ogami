<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release\Version2;

/**
 * Authoring warnings of a valid schema version 2 release (ADR 0018, decision 7): a threshold
 * consequence must lower its tracker, or it fires on every turn. In one step list, when a
 * "condition" on tracker T decides a nextScene S effect (some of its bands reach a nextScene S,
 * not all of them), and Scene Type S has no effect lowering T ("set", or "add" with a negative
 * literal), the release publishes with a warning. Warnings are computed, never stored.
 *
 * @internal
 */
final class AuthoringWarnings
{
    /**
     * @param list<array<string, mixed>> $sceneTypes canonical Scene Types
     * @param list<array<string, mixed>> $flows      canonical flows
     *
     * @return list<string> each starting with the path of the step holding the nextScene effect;
     *                      phase hooks first (flow, phase, hook order), then Scene Type step lists
     */
    public static function of(array $sceneTypes, array $flows): array
    {
        $lists = [];
        foreach ($flows as $flowIndex => $flow) {
            /** @var list<array<string, mixed>> $phases */
            $phases = $flow['phases'];
            foreach ($phases as $phaseIndex => $phase) {
                foreach (StepParts::lists($phase, \sprintf('flows[%d].phases[%d]', $flowIndex, $phaseIndex), ReleaseVersion2::PHASE_HOOKS) as $path => $steps) {
                    $lists[$path] = $steps;
                }
            }
        }

        $lowered = [];
        foreach ($sceneTypes as $index => $sceneType) {
            /** @var string $key */
            $key = $sceneType['key'];
            $lowered[$key] = [];
            foreach (StepParts::lists($sceneType, \sprintf('sceneTypes[%d]', $index), ReleaseVersion2::SCENE_TYPE_PARTS) as $path => $steps) {
                $lists[$path] = $steps;
                $lowered[$key] += self::loweredTrackers($steps, $path);
            }
        }

        $warnings = [];
        foreach ($lists as $path => $steps) {
            foreach ($steps as $index => $step) {
                if ('condition' !== $step['kind'] || !\is_string($step['tracker'])) {
                    continue;
                }

                foreach (self::consequences($steps, $index, $path) as [$stepPath, $sceneType]) {
                    if (!isset($lowered[$sceneType][$step['tracker']])) {
                        $warnings[] = \sprintf('%s: nextScene %s does not lower tracker %s; the consequence may fire every turn', $stepPath, $sceneType, $step['tracker']);
                    }
                }
            }
        }

        return array_values(array_unique($warnings));
    }

    /**
     * The Scene Types the condition at $index decides: forced by a nextScene effect some of its
     * bands reach but not all of them. A band reaches its own effects and every effect of the
     * steps its "next" (or the condition's default) leads to, following every outcome forward
     * until "end". Each decided Scene Type is reported once, at the first step forcing it.
     *
     * @param list<array<string, mixed>> $steps
     *
     * @return list<array{string, string}> [path of the step holding the effect, Scene Type key]
     */
    private static function consequences(array $steps, int $index, string $path): array
    {
        $keys = [];
        foreach ($steps as $at => $step) {
            if (\is_string($step['key'])) {
                $keys[$step['key']] = $at;
            }
        }

        $condition = $steps[$index];
        $reached = [];
        foreach (self::outcomes($condition['bands']) as $bandIndex => $band) {
            $stepPath = \sprintf('%s[%d]', $path, $index);
            $found = [];
            self::nextScenes(StepParts::effectList($band, \sprintf('%s.bands[%d]', $stepPath, $bandIndex)), $stepPath, $found);
            $pending = [self::target($band['next'] ?? $condition['next'] ?? null, $index, $keys)];
            $visited = [];
            while ([] !== $pending) {
                $at = array_pop($pending);
                if (null === $at || isset($visited[$at]) || !isset($steps[$at])) {
                    continue;
                }

                $visited[$at] = true;
                $stepPath = \sprintf('%s[%d]', $path, $at);
                self::nextScenes(StepParts::effects($steps[$at], $stepPath), $stepPath, $found);
                foreach (self::successors($steps[$at]) as $next) {
                    $pending[] = self::target($next, $at, $keys);
                }
            }

            $reached[] = $found;
        }

        $stepPaths = [];
        foreach ($reached as $found) {
            foreach ($found as $sceneType => $paths) {
                $stepPaths[$sceneType] = [...$stepPaths[$sceneType] ?? [], ...$paths];
            }
        }

        $decided = [];
        foreach (array_diff_key($stepPaths, array_intersect_key(...$reached)) as $sceneType => $paths) {
            usort($paths, strnatcmp(...));
            $decided[] = [$paths[0], (string) $sceneType];
        }

        usort($decided, static fn (array $a, array $b): int => strnatcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]));

        return $decided;
    }

    /**
     * Adds the Scene Types these effects force to $found.
     *
     * @param iterable<string, array<string, mixed>> $effects
     * @param array<string, list<string>>            $found   Scene Type key => step paths
     */
    private static function nextScenes(iterable $effects, string $stepPath, array &$found): void
    {
        foreach ($effects as $effect) {
            if ('nextScene' === $effect['kind'] && \is_string($effect['sceneType'])) {
                $found[$effect['sceneType']][] = $stepPath;
            }
        }
    }

    /**
     * The "next" values a step may continue with (null: the following step).
     *
     * @param array<string, mixed> $step
     *
     * @return list<mixed>
     */
    private static function successors(array $step): array
    {
        $default = $step['next'] ?? null;
        $outcomes = [...self::outcomes($step['bands'] ?? []), ...self::outcomes($step['options'] ?? []), ...self::outcomes($step['branches'] ?? []), ...self::outcomes([$step['otherwise'] ?? null])];
        $nexts = array_map(static fn (array $outcome): mixed => $outcome['next'] ?? $default, $outcomes);
        $exhaustive = \in_array($step['kind'], ['condition', 'choice'], true)
            || ('roll' === $step['kind'] && [] !== $outcomes)
            || isset($step['otherwise'])
            || (\is_array($step['branches'] ?? null) && isset($step['branches']['yes'], $step['branches']['no']));

        return $exhaustive ? $nexts : [...$nexts, $default];
    }

    /**
     * The outcomes of a canonical list or map; an empty band is an \stdClass with no parts.
     *
     * @return list<array<string, mixed>>
     */
    private static function outcomes(mixed $value): array
    {
        $outcomes = [];
        foreach (\is_array($value) ? $value : [] as $outcome) {
            if (\is_array($outcome) || $outcome instanceof \stdClass) {
                /** @var array<string, mixed> $outcome */
                $outcome = (array) $outcome;
                $outcomes[] = $outcome;
            }
        }

        return $outcomes;
    }

    /**
     * @param array<string, int> $keys step key => index
     */
    private static function target(mixed $next, int $from, array $keys): ?int
    {
        return \is_string($next) ? $keys[$next] ?? null : $from + 1;
    }

    /**
     * @param list<array<string, mixed>> $steps
     *
     * @return array<string, true> the trackers some effect of these steps lowers
     */
    private static function loweredTrackers(array $steps, string $path): array
    {
        $lowered = [];
        foreach ($steps as $index => $step) {
            foreach (StepParts::effects($step, \sprintf('%s[%d]', $path, $index)) as $effect) {
                $lowers = 'tracker' === $effect['kind']
                    && ('set' === $effect['op'] || (\is_int($effect['value']) && $effect['value'] < 0));
                if ($lowers && \is_string($effect['tracker'])) {
                    $lowered[$effect['tracker']] = true;
                }
            }
        }

        return $lowered;
    }
}
