<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release\Version2;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseFields;

/**
 * The gear catalog of schema version 2 (ADR 0045): an optional list of priced entries that shops
 * sell and inventory fields hold. Keys are unique, and so are names, trimmed and ignoring case, so
 * an upgrade can match a list string to one entry. A category is a free label matched exactly.
 * The canonical catalog is [] when empty and leaves a "slots" of 1 out, so a release that never
 * used it keeps its content hash.
 *
 * @internal
 */
final class Gear
{
    public const int MAX_ENTRIES = 500;
    public const int MAX_NAME_LENGTH = 60;
    public const int MAX_COST = 1_000_000;
    public const int MAX_SLOTS = 100;
    public const int DEFAULT_SLOTS = 1;
    public const int MAX_CATEGORY_LENGTH = 40;
    public const int MAX_CATEGORIES = 20;
    public const int MAX_DESCRIPTION_LENGTH = 500;

    private const array ENTRY = ['key' => true, 'name' => true, 'cost' => true, 'slots' => false, 'category' => false, 'description' => false];

    /**
     * @return array{list<array<string, mixed>>, array<string, string|null>} the canonical entries, and the category of each entry by key (null without one)
     */
    public static function validate(mixed $value): array
    {
        $entries = [];
        $categories = [];
        $names = [];
        foreach (ReleaseFields::sizedList($value ?? [], 'gear', 0, self::MAX_ENTRIES, 'gear entries') as $index => $entry) {
            $path = \sprintf('gear[%d]', $index);
            $entry = ReleaseFields::object($entry, $path, self::ENTRY);
            $key = ReleaseFields::key($entry['key'], $path.'.key');
            if (\array_key_exists($key, $categories)) {
                throw InvalidReleaseContent::at($path.'.key', \sprintf('duplicate gear key "%s"', $key));
            }

            $name = trim(ReleaseFields::text($entry['name'], $path.'.name', 1, self::MAX_NAME_LENGTH));
            $folded = mb_strtolower($name);
            if (isset($names[$folded])) {
                throw InvalidReleaseContent::at($path.'.name', \sprintf('duplicate gear name "%s", names are unique trimmed and ignoring case', $name));
            }

            $names[$folded] = true;
            ReleaseFields::integer($entry['cost'], $path.'.cost', 0, self::MAX_COST);
            if (isset($entry['slots']) && self::DEFAULT_SLOTS === ReleaseFields::integer($entry['slots'], $path.'.slots', 0, self::MAX_SLOTS)) {
                unset($entry['slots']);
            }

            if (isset($entry['category'])) {
                self::category($entry['category'], $path.'.category');
            }

            if (isset($entry['description'])) {
                ReleaseFields::text($entry['description'], $path.'.description', 0, self::MAX_DESCRIPTION_LENGTH);
            }

            /** @var string|null $category */
            $category = $entry['category'] ?? null;
            $categories[$key] = $category;
            $entries[] = $entry;
        }

        return [$entries, $categories];
    }

    public static function category(mixed $value, string $path): string
    {
        return ReleaseFields::text($value, $path, 1, self::MAX_CATEGORY_LENGTH);
    }

    /**
     * 1 to 20 distinct category labels, each the category of an entry of the catalog.
     *
     * @param array<string, true> $known the categories of the catalog
     *
     * @return list<string>
     */
    public static function categories(mixed $value, string $path, array $known, int $min = 1): array
    {
        $categories = [];
        foreach (ReleaseFields::sizedList($value, $path, $min, self::MAX_CATEGORIES, 'categories') as $index => $category) {
            $categoryPath = \sprintf('%s[%d]', $path, $index);
            $category = self::category($category, $categoryPath);
            if (!isset($known[$category])) {
                throw InvalidReleaseContent::at($categoryPath, \sprintf('no gear of the catalog has category "%s"', $category));
            }

            if (\in_array($category, $categories, true)) {
                throw InvalidReleaseContent::at($categoryPath, \sprintf('duplicate category "%s"', $category));
            }

            $categories[] = $category;
        }

        return $categories;
    }

    /**
     * Whether an inventory field with these categories (null: every entry) accepts gear of this category.
     *
     * @param list<string>|null $accepted
     */
    public static function accepts(?array $accepted, ?string $category): bool
    {
        return null === $accepted || (null !== $category && \in_array($category, $accepted, true));
    }
}
