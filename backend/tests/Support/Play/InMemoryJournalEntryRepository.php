<?php

declare(strict_types=1);

namespace App\Tests\Support\Play;

use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Journal\JournalEntry;
use App\Play\Domain\Journal\JournalEntryAlreadyExists;
use App\Play\Domain\Journal\JournalEntryId;
use App\Play\Domain\Journal\JournalEntryRepository;

/**
 * Entries are immutable, so keeping the instances is as safe as keeping copies.
 */
final class InMemoryJournalEntryRepository implements JournalEntryRepository
{
    /** @var array<string, JournalEntry> by id */
    private array $entries = [];

    /**
     * @throws JournalEntryAlreadyExists on a duplicate id, like the primary key
     */
    public function add(JournalEntry $entry): void
    {
        $id = $entry->id()->toString();
        if (isset($this->entries[$id])) {
            throw JournalEntryAlreadyExists::withId($entry->id());
        }

        $this->entries[$id] = $entry;
    }

    public function ofId(JournalEntryId $id): ?JournalEntry
    {
        return $this->entries[$id->toString()] ?? null;
    }

    public function ofCampaign(CampaignId $campaignId): array
    {
        $entries = array_values(array_filter(
            $this->entries,
            static fn (JournalEntry $entry): bool => $entry->campaignId()->equals($campaignId),
        ));

        usort($entries, static fn (JournalEntry $a, JournalEntry $b): int => [$a->recordedAt(), $a->id()->toString()] <=> [$b->recordedAt(), $b->id()->toString()]);

        return $entries;
    }
}
