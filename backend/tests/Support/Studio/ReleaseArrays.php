<?php

declare(strict_types=1);

namespace App\Tests\Support\Studio;

/**
 * Edits decoded release arrays by dotted path ("oracles.tables.0.name") for tests.
 */
final class ReleaseArrays
{
    /**
     * Sets the value at a dotted path, creating the last segment.
     *
     * @param array<mixed> $node
     *
     * @return array<mixed>
     */
    public static function with(array $node, string $path, mixed $value): array
    {
        $segments = explode('.', $path, 2);
        $segment = $segments[0];
        $rest = $segments[1] ?? null;
        if (null === $rest) {
            $node[$segment] = $value;

            return $node;
        }

        $child = $node[$segment] ?? null;
        if (!\is_array($child)) {
            throw new \LogicException(\sprintf('No array at "%s".', $segment));
        }

        $node[$segment] = self::with($child, $rest, $value);

        return $node;
    }

    /**
     * Removes the value at a dotted path.
     *
     * @param array<mixed> $node
     *
     * @return array<mixed>
     */
    public static function without(array $node, string $path): array
    {
        $segments = explode('.', $path, 2);
        $segment = $segments[0];
        $rest = $segments[1] ?? null;
        if (null === $rest) {
            unset($node[$segment]);

            return $node;
        }

        $child = $node[$segment] ?? null;
        if (!\is_array($child)) {
            throw new \LogicException(\sprintf('No array at "%s".', $segment));
        }

        $node[$segment] = self::without($child, $rest);

        return $node;
    }

    /**
     * Reverses the key order of every object (string-keyed array), keeping lists in order.
     *
     * @param array<mixed> $node
     *
     * @return array<mixed>
     */
    public static function reverseKeys(array $node): array
    {
        $node = array_map(static fn (mixed $value): mixed => \is_array($value) ? self::reverseKeys($value) : $value, $node);

        return array_is_list($node) ? $node : array_reverse($node, true);
    }

    /**
     * A release fixture of tests/Fixtures/Studio/releases, decoded with objects as arrays.
     *
     * @return array<mixed>
     */
    public static function fixture(string $name): array
    {
        $json = file_get_contents(__DIR__.'/../../Fixtures/Studio/releases/'.$name.'.json');
        if (false === $json) {
            throw new \LogicException(\sprintf('No release fixture "%s".', $name));
        }

        $release = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($release)) {
            throw new \LogicException(\sprintf('Release fixture "%s" is not a JSON object.', $name));
        }

        return $release;
    }
}
