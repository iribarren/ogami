<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\NoCurrentScene;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Play\Domain\Journal\FlowStepSnapshot;
use App\Play\Domain\Journal\InvalidJournalEntryId;
use App\Play\Domain\Journal\JournalEntry;
use App\Play\Domain\Journal\JournalEntryAlreadyExists;
use App\Play\Domain\Journal\JournalEntryContent;
use App\Play\Domain\Journal\JournalEntryId;
use App\Play\Domain\Journal\JournalEntryRepository;

/**
 * Records journal entries on behalf of a player: what every Record* handler shares. A handler
 * loads the campaign, builds the content (rolling when needed), then records it; any failure on
 * the way records nothing.
 */
final readonly class CampaignJournal
{
    public function __construct(
        private OwnedCampaigns $ownedCampaigns,
        private JournalEntryRepository $entries,
        private PublishedGameSystemReleases $releases,
        private Clock $clock,
    ) {
    }

    /**
     * The player's campaign to record in: only its owner can write its journal.
     *
     * @throws CampaignNotFound
     */
    public function campaign(string $campaignId, string $userId): Campaign
    {
        return $this->ownedCampaigns->get($campaignId, $userId);
    }

    /**
     * Records the content in the campaign's current scene, at the clock's time.
     *
     * @throws InvalidJournalEntryId
     * @throws JournalEntryAlreadyExists when the entry id is already taken (checked here, whatever the repository does)
     * @throws NoCurrentScene
     */
    public function record(string $entryId, Campaign $campaign, JournalEntryContent $content): void
    {
        $this->add($this->entry($entryId, $campaign, $content, $this->clock->now()));
    }

    /**
     * Builds the entry the content makes in the campaign's current scene without recording it, so a
     * change to the campaign can run in between (a Flow step is completed in the scene it was in).
     *
     * @param ?FlowStepSnapshot $flowStep the Flow step recording it, null for a free entry
     *
     * @throws InvalidJournalEntryId
     * @throws JournalEntryAlreadyExists when the entry id is already taken (checked here, whatever the repository does)
     * @throws NoCurrentScene
     */
    public function entry(string $entryId, Campaign $campaign, JournalEntryContent $content, \DateTimeImmutable $recordedAt, ?FlowStepSnapshot $flowStep = null): JournalEntry
    {
        $id = JournalEntryId::fromString($entryId);
        if ($this->entries->ofId($id) instanceof JournalEntry) {
            throw JournalEntryAlreadyExists::withId($id);
        }

        return JournalEntry::record($id, $campaign, $content, $recordedAt, $flowStep);
    }

    public function add(JournalEntry $entry): void
    {
        $this->entries->add($entry);
    }

    /**
     * The release the campaign is pinned to: oracles are always asked there, never in the latest
     * release (ADR 0014).
     *
     * @throws GameSystemReleaseNotFound
     * @throws UnsupportedReleaseSchemaVersion
     * @throws InvalidGameSystemRelease
     */
    public function pinnedSnapshot(Campaign $campaign): GameSystemSnapshot
    {
        $pinned = $campaign->pinnedRelease();

        return $this->releases->get($pinned->gameSystemKey(), $pinned->releaseVersion());
    }
}
