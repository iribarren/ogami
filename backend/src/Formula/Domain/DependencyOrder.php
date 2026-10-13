<?php

declare(strict_types=1);

namespace App\Formula\Domain;

/**
 * Orders named formulas so that each comes after the ones it reads, and rejects cycles.
 */
final class DependencyOrder
{
    /**
     * Only `{key}` references to other keys of the map count: `{key.max}` and `{tracker:key}` read other
     * things, and keys outside the map are left to the caller to check. Among the keys that are ready, the
     * one that comes first in the input goes first.
     *
     * @param array<string, Formula> $formulasByKey
     *
     * @return list<string>
     *
     * @throws InvalidFormula when formulas depend on each other in a cycle
     */
    public static function of(array $formulasByKey): array
    {
        $pending = [];
        foreach ($formulasByKey as $key => $formula) {
            $pending[(string) $key] = self::dependenciesOf($formula, $formulasByKey);
        }

        $order = [];
        while ([] !== $pending) {
            $ready = self::firstReady($pending);
            if (null === $ready) {
                throw InvalidFormula::cycle(self::cycleIn($pending));
            }

            $order[] = $ready;
            unset($pending[$ready]);
            foreach ($pending as $key => $dependencies) {
                $pending[$key] = array_values(array_diff($dependencies, [$ready]));
            }
        }

        return $order;
    }

    /**
     * @param array<string, Formula> $formulasByKey
     *
     * @return list<string>
     */
    private static function dependenciesOf(Formula $formula, array $formulasByKey): array
    {
        $dependencies = [];
        foreach ($formula->references() as $reference) {
            if (ReferenceKind::Field === $reference->kind && \array_key_exists($reference->key, $formulasByKey)) {
                $dependencies[] = $reference->key;
            }
        }

        return $dependencies;
    }

    /**
     * @param array<string, list<string>> $pending
     */
    private static function firstReady(array $pending): ?string
    {
        foreach ($pending as $key => $dependencies) {
            if ([] === $dependencies) {
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * Follows the first unresolved dependency from the first pending key until a key repeats.
     * Every pending key has one, so the walk always ends in a cycle.
     *
     * @param array<string, list<string>> $pending
     *
     * @return list<string>
     */
    private static function cycleIn(array $pending): array
    {
        $path = [(string) array_key_first($pending)];
        while (true) {
            $next = $pending[array_last($path)][0];
            $seenAt = array_search($next, $path, true);
            if (false !== $seenAt) {
                return [...\array_slice($path, $seenAt), $next];
            }

            $path[] = $next;
        }
    }
}
