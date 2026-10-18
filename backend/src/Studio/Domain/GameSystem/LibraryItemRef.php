<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

use App\Studio\Domain\Release\InvalidReleaseContent;

/**
 * An item of a library pack, or of a draft's section: its section and its key.
 */
final readonly class LibraryItemRef
{
    public function __construct(
        public LibrarySection $section,
        public string $key,
    ) {
    }

    /**
     * The items of a request, which is untrusted input: a non-empty list of {section, key} objects.
     *
     * @return non-empty-list<self>
     *
     * @throws InvalidReleaseContent naming the path of the first offending value
     */
    public static function listFromArray(mixed $items): array
    {
        if (!\is_array($items) || !array_is_list($items) || [] === $items) {
            throw InvalidReleaseContent::at('items', 'must be a non-empty list');
        }

        $refs = [];
        foreach ($items as $index => $item) {
            $section = \is_array($item) && \is_string($item['section'] ?? null) ? LibrarySection::tryFrom($item['section']) : null;
            if (!$section instanceof LibrarySection) {
                throw InvalidReleaseContent::at(\sprintf('items[%d].section', $index), \sprintf('must be one of %s', implode(', ', array_column(LibrarySection::cases(), 'value'))));
            }

            if (!\is_string($item['key'] ?? null) || '' === $item['key']) {
                throw InvalidReleaseContent::at(\sprintf('items[%d].key', $index), 'must be a text');
            }

            $refs[] = new self($section, $item['key']);
        }

        return $refs;
    }

    /**
     * Names the item among others: the same text for the same item.
     */
    public function id(): string
    {
        return $this->section->value.'/'.$this->key;
    }

    public function describe(): string
    {
        return \sprintf('%s "%s"', $this->section->noun(), $this->key);
    }

    public function is(self $other): bool
    {
        return $this->section === $other->section && $this->key === $other->key;
    }

    /**
     * @return array{section: string, key: string}
     */
    public function toArray(): array
    {
        return ['section' => $this->section->value, 'key' => $this->key];
    }
}
