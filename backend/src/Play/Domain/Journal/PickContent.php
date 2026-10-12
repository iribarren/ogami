<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

use App\Play\Domain\GameSystem\Flow\ItemKind;

/**
 * What a pick step picked: the kind of item (NPCs or Threads), the release Tag keys it asked for
 * and the items picked, with their names as they were at pick time. Nothing picked is an empty
 * list ("none").
 *
 * @phpstan-type PickedData array{id: string, name: string}
 */
final readonly class PickContent implements JournalEntryContent
{
    public const string KIND = 'pick';

    public const int MAX_PICKED = 10;

    /**
     * @param list<string>     $tags
     * @param list<PickedData> $picked
     */
    private function __construct(
        private ItemKind $of,
        private array $tags,
        private array $picked,
    ) {
    }

    /**
     * @param list<string>     $tags   release Tag keys the step asked for
     * @param list<PickedData> $picked in the order picked
     *
     * @throws InvalidJournalEntryContent when a tag, id or name is blank, an id repeats or more than 10 items are picked
     */
    public static function of(ItemKind $of, array $tags, array $picked): self
    {
        if (\count($picked) > self::MAX_PICKED) {
            throw InvalidJournalEntryContent::malformed('picked', \sprintf('at most %d items, got %d', self::MAX_PICKED, \count($picked)));
        }

        foreach ($tags as $tag) {
            if ('' === trim($tag)) {
                throw InvalidJournalEntryContent::blank('A pick tag key');
            }
        }

        $ids = [];
        foreach ($picked as $item) {
            foreach (['A picked id' => $item['id'], 'A picked name' => $item['name']] as $what => $value) {
                if ('' === trim($value)) {
                    throw InvalidJournalEntryContent::blank($what);
                }
            }

            if (isset($ids[$item['id']])) {
                throw InvalidJournalEntryContent::malformed('picked', \sprintf('distinct ids, "%s" repeats', $item['id']));
            }

            $ids[$item['id']] = true;
        }

        return new self($of, $tags, $picked);
    }

    /**
     * @param array<mixed> $data
     *
     * @throws InvalidJournalEntryContent
     */
    public static function fromArray(array $data): self
    {
        $of = ItemKind::tryFrom(ContentData::string($data, 'of')) ?? throw InvalidJournalEntryContent::malformed('of', 'one of "npc", "thread"');
        $listed = $data['tags'] ?? null;
        if (!\is_array($listed) || !array_is_list($listed)) {
            throw InvalidJournalEntryContent::malformed('tags', 'a list of strings');
        }

        $tags = [];
        foreach ($listed as $tag) {
            $tags[] = \is_string($tag) ? $tag : throw InvalidJournalEntryContent::malformed('tags', 'a list of strings');
        }

        return self::of(
            $of,
            $tags,
            array_map(
                static fn (array $item): array => ['id' => ContentData::string($item, 'id'), 'name' => ContentData::string($item, 'name')],
                ContentData::listOfArrays($data, 'picked'),
            ),
        );
    }

    public function kind(): string
    {
        return self::KIND;
    }

    public function itemKind(): ItemKind
    {
        return $this->of;
    }

    /**
     * @return list<string> release Tag keys
     */
    public function tags(): array
    {
        return $this->tags;
    }

    /**
     * @return list<PickedData> in the order picked; empty when nothing was
     */
    public function picked(): array
    {
        return $this->picked;
    }

    /**
     * @return array{kind: string, of: string, tags: list<string>, picked: list<PickedData>}
     */
    public function toArray(): array
    {
        return ['kind' => self::KIND, 'of' => $this->of->value, 'tags' => $this->tags, 'picked' => $this->picked];
    }
}
