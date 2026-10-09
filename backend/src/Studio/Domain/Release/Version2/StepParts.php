<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release\Version2;

/**
 * Walks the parts of canonical schema version 2 steps, with their paths: the outcomes that may
 * carry "next" and "effects" (the step itself, its bands, branches, "otherwise" and options).
 *
 * @internal
 */
final class StepParts
{
    /**
     * @param array<string, mixed> $step
     *
     * @return iterable<string, array<string, mixed>> path => outcome
     */
    public static function outcomes(array $step, string $path): iterable
    {
        yield $path => $step;

        foreach (['bands', 'options'] as $name) {
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
}
