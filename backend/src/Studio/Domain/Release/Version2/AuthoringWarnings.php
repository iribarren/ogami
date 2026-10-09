<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release\Version2;

/**
 * Authoring warnings of a valid schema version 2 release (ADR 0018, decision 7): a threshold
 * consequence must lower its tracker, or it fires on every turn. In one step list, when a
 * "condition" on tracker T is followed (in the same step or a later one) by a nextScene S effect,
 * and Scene Type S has no effect lowering T ("set", or "add" with a negative literal), the release
 * publishes with a warning. Warnings are computed, never stored.
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
            $conditioned = [];
            foreach ($steps as $index => $step) {
                $stepPath = \sprintf('%s[%d]', $path, $index);
                if ('condition' === $step['kind'] && \is_string($step['tracker'])) {
                    $conditioned[$step['tracker']] = true;
                }

                foreach (StepParts::effects($step, $stepPath) as $effect) {
                    if ('nextScene' !== $effect['kind'] || !\is_string($effect['sceneType'])) {
                        continue;
                    }

                    foreach (array_keys($conditioned) as $tracker) {
                        if (!isset($lowered[$effect['sceneType']][$tracker])) {
                            $warnings[] = \sprintf('%s: nextScene %s does not lower tracker %s; the consequence may fire every turn', $stepPath, $effect['sceneType'], $tracker);
                        }
                    }
                }
            }
        }

        return array_values(array_unique($warnings));
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
