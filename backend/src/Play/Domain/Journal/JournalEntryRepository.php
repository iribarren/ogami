<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

use App\Play\Domain\Campaign\CampaignId;

/**
 * Port: where journal entries are kept. Entries are only added, and the push of a pool check completes
 * one entry once (savePush()): the only change an entry's content can take.
 */
interface JournalEntryRepository
{
    /**
     * @throws JournalEntryAlreadyExists when an entry with the same id is already kept
     */
    public function add(JournalEntry $entry): void;

    /**
     * Keeps the push that completed an entry (JournalEntry::pushedWith()): it replaces the stored
     * content only while the stored entry has no push yet, so two pushes of one roll cannot both
     * succeed, and nothing else of the entry changes. Within the command bus transaction, so a
     * failed push leaves the entry as it was.
     *
     * @param bool $asLatest true also requires that the stored entry is still the campaign's latest
     *                       (a push from the sheet); a Flow step's push takes its own entry wherever it is
     *
     * @throws CheckAlreadyPushed       when the stored entry was pushed already, also by a concurrent push
     * @throws JournalEntryNotTheLatest with $asLatest, when the campaign has a later entry, also one a concurrent request recorded
     */
    public function savePush(JournalEntry $pushed, bool $asLatest = false): void;

    public function ofId(JournalEntryId $id): ?JournalEntry;

    /**
     * The campaign's last entry, the one ofCampaign() lists last (recordedAt, then id); null for an empty journal.
     */
    public function latestOfCampaign(CampaignId $campaignId): ?JournalEntry;

    /**
     * The journal of one campaign, in recording order (recordedAt, then id, ascending).
     *
     * @return list<JournalEntry>
     */
    public function ofCampaign(CampaignId $campaignId): array;
}
