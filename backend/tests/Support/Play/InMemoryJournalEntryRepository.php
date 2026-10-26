<?php

declare(strict_types=1);

namespace App\Tests\Support\Play;

use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Journal\CheckAlreadyPushed;
use App\Play\Domain\Journal\CheckContent;
use App\Play\Domain\Journal\JournalEntry;
use App\Play\Domain\Journal\JournalEntryAlreadyExists;
use App\Play\Domain\Journal\JournalEntryId;
use App\Play\Domain\Journal\JournalEntryNotTheLatest;
use App\Play\Domain\Journal\JournalEntryRepository;
use App\Play\Domain\Journal\OracleTableContent;
use App\Play\Domain\Journal\RandomEventAlreadyRolled;

/**
 * Entries are immutable but for a push, which replaces one by its pushed copy, so keeping the
 * instances is as safe as keeping copies.
 */
final class InMemoryJournalEntryRepository implements JournalEntryRepository
{
    /** @var array<string, JournalEntry> by id */
    private array $entries = [];

    /**
     * @throws JournalEntryAlreadyExists on a duplicate id, like the primary key
     * @throws RandomEventAlreadyRolled  on a second roll for one question, like the unique index
     */
    public function add(JournalEntry $entry): void
    {
        $id = $entry->id()->toString();
        if (isset($this->entries[$id])) {
            throw JournalEntryAlreadyExists::withId($entry->id());
        }

        $content = $entry->content();
        if ($content instanceof OracleTableContent && null !== $content->forEntry()) {
            foreach ($this->entries as $kept) {
                if ($kept->content() instanceof OracleTableContent && $kept->content()->forEntry() === $content->forEntry()) {
                    throw RandomEventAlreadyRolled::forEntry($content->forEntry());
                }
            }
        }

        $this->entries[$id] = $entry;
    }

    /**
     * @throws CheckAlreadyPushed       when the kept entry was pushed already, like the conditional update
     * @throws JournalEntryNotTheLatest with $asLatest, when the campaign has a later entry, like the conditional update
     */
    public function savePush(JournalEntry $pushed, bool $asLatest = false): void
    {
        $kept = $this->entries[$pushed->id()->toString()] ?? throw new \LogicException(\sprintf('Journal entry "%s" is not kept.', $pushed->id()->toString()));
        if ($asLatest && !$this->latestOfCampaign($kept->campaignId())?->id()->equals($kept->id())) {
            throw JournalEntryNotTheLatest::entry($pushed->id());
        }

        $content = $kept->content();
        if ($content instanceof CheckContent && $content->pool()?->push instanceof \App\Play\Domain\Journal\CheckPushContent) {
            throw CheckAlreadyPushed::entry($pushed->id());
        }

        $this->entries[$pushed->id()->toString()] = $pushed;
    }

    public function lockCampaignForPush(CampaignId $campaignId): void
    {
        // Nothing to lock: no concurrent transactions.
    }

    public function latestOfCampaign(CampaignId $campaignId): ?JournalEntry
    {
        $entries = $this->ofCampaign($campaignId);

        return [] === $entries ? null : array_last($entries);
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
