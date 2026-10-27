<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Sheet;

use App\Play\Domain\GameSystem\GearEntry;

/**
 * A list of stacks of gear (ADR 0045). The value is a list of catalog stacks {gear, quantity} and
 * free stacks {name, quantity, slots}; the used slots, the sum of quantity times slots, stay within
 * `capacity` (no limit when null), and at most `maxStacks` stacks are held. It accepts the catalog
 * entries whose category is one of `categories` (every entry, with or without a category, when
 * null); the entries are read from the catalog when the release is translated, so the field checks
 * a value on its own. A formula cannot read it and a sheet effect cannot change it.
 */
final readonly class InventoryField extends Field
{
    public const int MAX_CAPACITY = 999;

    public const int MAX_STACKS = 100;

    public const int DEFAULT_MAX_STACKS = 50;

    public const int MAX_CATEGORIES = 20;

    /** @var array<string, GearEntry> the accepted entries by key, in catalog order */
    private array $gear;

    /**
     * @param ?list<string>   $categories the categories it accepts, null for every entry
     * @param list<GearEntry> $catalog    the release's whole gear catalog
     *
     * @throws \InvalidArgumentException when a limit is out of range or a category is repeated or has no entry in the catalog
     */
    public function __construct(
        string $key,
        string $label,
        ?string $hint = null,
        ?string $group = null,
        public ?int $capacity = null,
        public int $maxStacks = self::DEFAULT_MAX_STACKS,
        public ?array $categories = null,
        array $catalog = [],
        int $span = 1,
    ) {
        if (null !== $capacity && ($capacity < 1 || $capacity > self::MAX_CAPACITY)) {
            throw new \InvalidArgumentException(\sprintf('Inventory field "%s" holds 1 to %d slots.', $key, self::MAX_CAPACITY));
        }

        if ($maxStacks < 1 || $maxStacks > self::MAX_STACKS) {
            throw new \InvalidArgumentException(\sprintf('Inventory field "%s" holds 1 to %d stacks.', $key, self::MAX_STACKS));
        }

        if (null !== $categories) {
            if ([] === $categories || \count($categories) > self::MAX_CATEGORIES || \count(array_unique($categories)) !== \count($categories)) {
                throw new \InvalidArgumentException(\sprintf('Inventory field "%s" accepts 1 to %d distinct categories.', $key, self::MAX_CATEGORIES));
            }

            foreach ($categories as $category) {
                if (!array_any($catalog, static fn (GearEntry $entry): bool => $entry->category === $category)) {
                    throw new \InvalidArgumentException(\sprintf('Inventory field "%s" accepts category "%s", which no gear has.', $key, $category));
                }
            }
        }

        $accepted = [];
        foreach ($catalog as $entry) {
            if (null === $categories || (null !== $entry->category && \in_array($entry->category, $categories, true))) {
                $accepted[$entry->key] = $entry;
            }
        }

        $this->gear = $accepted;
        parent::__construct($key, $label, $hint, $group, $span);
    }

    /**
     * @return list<GearEntry> the entries it accepts, in catalog order
     */
    public function gear(): array
    {
        return array_values($this->gear);
    }

    /**
     * The accepted entry with this key; null when the catalog has none or the field does not accept its category.
     */
    public function entry(string $key): ?GearEntry
    {
        return $this->gear[$key] ?? null;
    }
}
