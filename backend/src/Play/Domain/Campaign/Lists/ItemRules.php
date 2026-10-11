<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Lists;

use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\Tag;

/**
 * The rules NPCs and Threads share: trimmed names, unique release Tags and free tags, a weight.
 * Every violation fails through the factory the caller passes (InvalidNpc or InvalidThread), so
 * the message names the right kind; nothing is clamped or deduplicated silently.
 */
final class ItemRules
{
    public const int MAX_TAGS = 10;

    public const int MAX_FREE_TAGS = 10;

    public const int MAX_FREE_TAG_LENGTH = 50;

    public const int MIN_WEIGHT = 1;

    public const int MAX_WEIGHT = 3;

    public const int DEFAULT_WEIGHT = 1;

    /**
     * @param \Closure(string): \DomainException $invalid
     *
     * @return string the trimmed name
     */
    public static function name(string $name, int $max, \Closure $invalid): string
    {
        $name = trim($name);
        if ('' === $name) {
            throw $invalid('The name must not be blank.');
        }

        $length = mb_strlen($name);
        if ($length > $max) {
            throw $invalid(\sprintf('The name must be at most %d characters, got %d.', $max, $length));
        }

        return $name;
    }

    /**
     * @param list<string>                       $tags
     * @param \Closure(string): \DomainException $invalid
     *
     * @return list<string>
     */
    public static function tags(array $tags, \Closure $invalid): array
    {
        if (\count($tags) > self::MAX_TAGS) {
            throw $invalid(\sprintf('At most %d tags are allowed, got %d.', self::MAX_TAGS, \count($tags)));
        }

        $seen = [];
        foreach ($tags as $key) {
            if (isset($seen[$key])) {
                throw $invalid(\sprintf('Tag "%s" is listed twice.', $key));
            }

            $seen[$key] = true;
        }

        return $tags;
    }

    /**
     * @param list<string>                       $freeTags
     * @param \Closure(string): \DomainException $invalid
     *
     * @return list<string> trimmed, in the player's spelling
     */
    public static function freeTags(array $freeTags, \Closure $invalid): array
    {
        if (\count($freeTags) > self::MAX_FREE_TAGS) {
            throw $invalid(\sprintf('At most %d free tags are allowed, got %d.', self::MAX_FREE_TAGS, \count($freeTags)));
        }

        $seen = [];
        $trimmed = [];
        foreach ($freeTags as $freeTag) {
            $freeTag = trim($freeTag);
            $length = mb_strlen($freeTag);
            if (0 === $length || $length > self::MAX_FREE_TAG_LENGTH) {
                throw $invalid(\sprintf('A free tag must be 1 to %d characters, got %d.', self::MAX_FREE_TAG_LENGTH, $length));
            }

            $folded = mb_strtolower($freeTag);
            if (isset($seen[$folded])) {
                throw $invalid(\sprintf('Free tag "%s" is listed twice.', $freeTag));
            }

            $seen[$folded] = true;
            $trimmed[] = $freeTag;
        }

        return $trimmed;
    }

    /**
     * @param \Closure(string): \DomainException $invalid
     */
    public static function weight(int $weight, \Closure $invalid): int
    {
        if ($weight < self::MIN_WEIGHT || $weight > self::MAX_WEIGHT) {
            throw $invalid(\sprintf('The weight must be %d to %d, got %d.', self::MIN_WEIGHT, self::MAX_WEIGHT, $weight));
        }

        return $weight;
    }

    /**
     * @param list<string> $tags
     *
     * @throws UnknownReleaseTag when the release defines none of a key
     */
    public static function assertInRelease(array $tags, GameSystemSnapshot $release): void
    {
        foreach ($tags as $key) {
            if (!$release->tag($key) instanceof Tag) {
                throw UnknownReleaseTag::withKey($key);
            }
        }
    }
}
