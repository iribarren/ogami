<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

use App\Studio\Domain\Release\InvalidReleaseContent;

/**
 * The library items of a release document, whether it is a pack's or a draft's: oracles (tables and
 * likelihood oracles), trackers, tags, fact slots and Scene Types (ADR 0033). The document may be
 * invalid, so it is read defensively: anything that is not an object with a string key is skipped.
 *
 * An item is found where the document keeps it: its list (a dotted path such as "oracles.tables")
 * and its index there. That is also the path prefix DraftReferences reports for references made
 * inside the item, which is how an item's dependencies are found.
 */
final class LibraryItems
{
    /** @var array<string, list<string>> the paths that name each item, by item id */
    private array $references = [];

    /**
     * @param array<string, mixed> $document
     */
    private function __construct(
        private readonly array $document,
    ) {
    }

    /**
     * @param array<string, mixed> $document
     */
    public static function of(array $document): self
    {
        return new self($document);
    }

    /**
     * Every item, in pack order: the sections in their order, tables before likelihood oracles,
     * then the order of the document.
     *
     * @return list<LibraryItemRef>
     */
    public function all(): array
    {
        $refs = [];
        foreach (LibrarySection::cases() as $section) {
            foreach ($section->lists() as $list) {
                foreach ($this->objects($this->document, $list) as $item) {
                    $refs[] = new LibraryItemRef($section, $item['key']);
                }
            }
        }

        return $refs;
    }

    /**
     * @return array{list: string, index: int, item: array<array-key, mixed>}|null where the document keeps the item
     */
    public function find(LibraryItemRef $ref): ?array
    {
        foreach ($ref->section->lists() as $list) {
            foreach ($this->objects($this->document, $list) as $index => $item) {
                if ($item['key'] === $ref->key) {
                    return ['list' => $list, 'index' => $index, 'item' => $item];
                }
            }
        }

        return null;
    }

    public function has(LibraryItemRef $ref): bool
    {
        return null !== $this->find($ref);
    }

    /**
     * The other items that this one names: the keys DraftReferences finds at paths inside it.
     *
     * @return list<LibraryItemRef>
     */
    public function dependenciesOf(LibraryItemRef $owner): array
    {
        $located = $this->find($owner);
        if (null === $located) {
            return [];
        }

        $prefix = \sprintf('%s[%d]', $located['list'], $located['index']);
        $dependencies = [];
        foreach ($this->all() as $candidate) {
            if ($candidate->is($owner)) {
                continue;
            }

            foreach ($this->pathsTo($candidate) as $path) {
                if (str_starts_with($path, $prefix)) {
                    $dependencies[] = $candidate;
                    break;
                }
            }
        }

        return $dependencies;
    }

    /**
     * The roots and everything they need, transitively. A needed item the draft already has is an
     * override: it is not copied and the search stops there.
     *
     * @param list<LibraryItemRef> $roots items of this document, always selected
     * @param self                 $draft the items the importing draft has
     *
     * @return array{list<LibraryItemRef>, list<LibraryItemRef>} the items to copy (the roots included) and the overrides, both in pack order
     */
    public function closure(array $roots, self $draft): array
    {
        $selected = [];
        $overrides = [];
        $queue = $roots;
        foreach ($roots as $root) {
            $selected[$root->id()] = true;
        }

        while (null !== ($ref = array_shift($queue))) {
            foreach ($this->dependenciesOf($ref) as $dependency) {
                $id = $dependency->id();
                if (isset($selected[$id]) || isset($overrides[$id])) {
                    continue;
                }

                if ($draft->has($dependency)) {
                    $overrides[$id] = true;

                    continue;
                }

                $selected[$id] = true;
                $queue[] = $dependency;
            }
        }

        $all = $this->all();

        return [
            array_values(array_filter($all, static fn (LibraryItemRef $ref): bool => isset($selected[$ref->id()]))),
            array_values(array_filter($all, static fn (LibraryItemRef $ref): bool => isset($overrides[$ref->id()]))),
        ];
    }

    /**
     * The document with the item added at the end of its list.
     *
     * @param array<string, mixed>    $document
     * @param array<array-key, mixed> $item
     *
     * @return array<string, mixed>
     *
     * @throws InvalidReleaseContent when the list is not a list
     */
    public static function appended(array $document, string $list, array $item): array
    {
        return self::withList($document, explode('.', $list), [...self::mutableList($document, $list), $item], $list);
    }

    /**
     * The document with the item at this index of the list replaced.
     *
     * @param array<string, mixed>    $document
     * @param array<array-key, mixed> $item
     *
     * @return array<string, mixed>
     *
     * @throws InvalidReleaseContent when the list is not a list
     */
    public static function replaced(array $document, string $list, int $index, array $item): array
    {
        $items = self::mutableList($document, $list);
        $items[$index] = $item;

        return self::withList($document, explode('.', $list), $items, $list);
    }

    /**
     * The document without the item at this index of the list.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     *
     * @throws InvalidReleaseContent when the list is not a list
     */
    public static function removed(array $document, string $list, int $index): array
    {
        $items = self::mutableList($document, $list);
        unset($items[$index]);

        return self::withList($document, explode('.', $list), array_values($items), $list);
    }

    /**
     * @return list<string>
     */
    private function pathsTo(LibraryItemRef $ref): array
    {
        return $this->references[$ref->id()] ??= match ($ref->section) {
            LibrarySection::Oracles => DraftReferences::oraclePathsTo($this->document, $ref->key),
            LibrarySection::Trackers => DraftReferences::trackerPathsTo($this->document, $ref->key),
            LibrarySection::Tags => DraftReferences::tagPathsTo($this->document, $ref->key),
            LibrarySection::FactSlots => DraftReferences::factSlotPathsTo($this->document, $ref->key),
            LibrarySection::SceneTypes => DraftReferences::sceneTypePathsTo($this->document, $ref->key),
        };
    }

    /**
     * The objects with a string key of a list of the document, by their index in it.
     *
     * @param array<string, mixed> $document
     *
     * @return iterable<int, array{key: string}&array<array-key, mixed>>
     */
    private function objects(array $document, string $list): iterable
    {
        $items = self::at($document, explode('.', $list));
        foreach (\is_array($items) && array_is_list($items) ? $items : [] as $index => $item) {
            if (\is_array($item) && \is_string($item['key'] ?? null)) {
                yield $index => $item;
            }
        }
    }

    /**
     * @param array<array-key, mixed> $node
     * @param list<string>            $parts
     */
    private static function at(array $node, array $parts): mixed
    {
        $value = $node;
        foreach ($parts as $part) {
            $value = \is_array($value) ? $value[$part] ?? null : null;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<array-key, mixed> the stored list; none when it is missing
     *
     * @throws InvalidReleaseContent when it is not a list
     */
    private static function mutableList(array $document, string $list): array
    {
        $items = self::at($document, explode('.', $list)) ?? [];
        if (!\is_array($items) || !array_is_list($items)) {
            throw InvalidReleaseContent::at($list, 'must be a list');
        }

        return $items;
    }

    /**
     * @param array<string, mixed>    $node
     * @param non-empty-list<string>  $parts
     * @param array<array-key, mixed> $items
     *
     * @return array<string, mixed>
     */
    private static function withList(array $node, array $parts, array $items, string $list): array
    {
        $head = $parts[0];
        $rest = \array_slice($parts, 1);
        if ([] === $rest) {
            $node[$head] = $items;

            return $node;
        }

        $child = $node[$head] ?? [];
        if (!\is_array($child)) {
            throw InvalidReleaseContent::at($list, 'must be a list');
        }

        /** @var array<string, mixed> $typed */
        $typed = $child;
        $node[$head] = self::withList($typed, $rest, $items, $list);

        return $node;
    }
}
