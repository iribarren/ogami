<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release\Version2;

use App\Studio\Domain\Release\InvalidReleaseContent;

/**
 * Namespaced placeholders (ADR 0018, decision 9). A "{…}" matching PATTERN is a placeholder, other
 * braces are text. Schema version 2 allows {tracker:key}, {step:key} and, in effect text only,
 * {answer}.
 *
 * @internal
 */
final class Placeholders
{
    public const string PATTERN = '/\{([a-z]+)(?::([a-z0-9-]+))?\}/';

    /**
     * @param array<string, mixed> $trackers the trackers in scope (a flow's, or the release's)
     * @param array<string, mixed> $steps    the step keys in scope
     * @param string|null          $flow     the flow key, or null for the release scope
     */
    public static function check(string $text, string $path, bool $effectText, array $trackers, array $steps, ?string $flow): void
    {
        preg_match_all(self::PATTERN, $text, $matches, \PREG_SET_ORDER);
        foreach ($matches as $match) {
            [$placeholder, $namespace] = $match;
            $key = $match[2] ?? null;
            if ('tracker' === $namespace && null !== $key) {
                if (!isset($trackers[$key])) {
                    throw InvalidReleaseContent::at($path, null === $flow ? \sprintf('placeholder %s: unknown tracker "%s"', $placeholder, $key) : \sprintf('placeholder %s: tracker "%s" is not one of the trackers of flow "%s"', $placeholder, $key, $flow));
                }
            } elseif ('step' === $namespace && null !== $key) {
                if (!isset($steps[$key])) {
                    throw InvalidReleaseContent::at($path, null === $flow ? \sprintf('placeholder %s: unknown step "%s"', $placeholder, $key) : \sprintf('placeholder %s: step "%s" is not in flow "%s" or its Scene Types', $placeholder, $key, $flow));
                }
            } elseif ('answer' === $namespace && null === $key) {
                if (!$effectText) {
                    throw InvalidReleaseContent::at($path, 'placeholder {answer} is only allowed in effect text');
                }
            } else {
                throw InvalidReleaseContent::at($path, \sprintf('placeholder %s is not supported in schema version 2', $placeholder));
            }
        }
    }
}
