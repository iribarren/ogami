<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\FlowRun\FlowNote;
use App\Play\Domain\Campaign\NoCurrentScene;
use App\Play\Domain\Campaign\Scene;
use App\Play\Domain\Campaign\Session;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Play\Domain\Journal\CheckContent;
use App\Play\Domain\Journal\FlowOutcomeContent;
use App\Play\Domain\Journal\FlowStepSnapshot;
use App\Play\Domain\Journal\InvalidJournalEntryId;
use App\Play\Domain\Journal\InvalidNoteAttachment;
use App\Play\Domain\Journal\JournalEntry;
use App\Play\Domain\Journal\JournalEntryAlreadyExists;
use App\Play\Domain\Journal\JournalEntryContent;
use App\Play\Domain\Journal\JournalEntryId;
use App\Play\Domain\Journal\JournalEntryRepository;
use App\Play\Domain\Journal\LikelihoodContent;
use App\Play\Domain\Journal\NotARandomEventQuestion;
use App\Play\Domain\Journal\OracleTableContent;
use App\Play\Domain\Journal\RandomEventAlreadyRolled;
use App\Play\Domain\Journal\RollContent;

/**
 * Records journal entries on behalf of a player: what every Record* handler shares. A handler
 * loads the campaign, builds the content (rolling when needed), then records it; any failure on
 * the way records nothing.
 */
final readonly class CampaignJournal
{
    /** The kinds of entry a note can attach: results the player rolled or asked for. */
    private const array ATTACHABLE_KINDS = [RollContent::KIND, OracleTableContent::KIND, LikelihoodContent::KIND, CheckContent::KIND];

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

    /**
     * Builds the entry recorded right after another, in its session and scene (JournalEntry::followedBy()).
     *
     * @throws InvalidJournalEntryId
     * @throws JournalEntryAlreadyExists when the entry id is already taken (checked here, whatever the repository does)
     */
    public function following(JournalEntry $entry, string $entryId, JournalEntryContent $content): JournalEntry
    {
        $id = JournalEntryId::fromString($entryId);
        if ($this->entries->ofId($id) instanceof JournalEntry) {
            throw JournalEntryAlreadyExists::withId($id);
        }

        return $entry->followedBy($id, $content);
    }

    /**
     * Takes the notes of what the Flow decided on its own in the command that just ran on the
     * campaign and builds the one flowOutcome entry they make, without recording it: right after
     * the entry given (the step's own, in its scene), else in the current scene at the clock's time.
     * Null when the command noted nothing, or when there is no scene to record in, in which case the
     * notes are dropped: the journal belongs to a scene.
     *
     * @param JournalEntryIdGenerator $entryIds generates the id of the entry, only when there is one
     * @param ?JournalEntry           $after    the entry the command recorded for its step, if any
     *
     * @throws InvalidJournalEntryId
     * @throws JournalEntryAlreadyExists when the entry id is already taken (checked here, whatever the repository does)
     */
    public function flowOutcome(JournalEntryIdGenerator $entryIds, Campaign $campaign, ?JournalEntry $after = null): ?JournalEntry
    {
        $notes = $campaign->takeFlowNotes();
        if ([] === $notes) {
            return null;
        }

        $entryId = $entryIds->generate()->toString();
        $content = FlowOutcomeContent::of(array_map(static fn (FlowNote $note): array => $note->toArray(), $notes));
        if ($after instanceof JournalEntry) {
            return $this->following($after, $entryId, $content);
        }

        try {
            return $this->entry($entryId, $campaign, $content, $this->clock->now());
        } catch (NoCurrentScene) {
            return null;
        }
    }

    /**
     * The likelihood answer whose random event a roll on the oracle table answers, checked before
     * anything is recorded: it is an entry of this campaign, recorded in the current session and
     * scene, its random event is that table, and no oracle table entry is tied to it yet. (The repository guards the last rule again against
     * a roll recorded at the same time.).
     *
     * @throws InvalidJournalEntryId    when the id is blank
     * @throws JournalEntryNotFound     when no entry of the campaign has this id
     * @throws NotARandomEventQuestion  when the entry is no likelihood answer with this random event, or is not in the current scene
     * @throws NoCurrentScene           when the campaign has no current scene
     * @throws RandomEventAlreadyRolled when an oracle table entry is already tied to the entry
     */
    public function assertRandomEventQuestion(string $entryId, Campaign $campaign, string $oracleKey): void
    {
        $entry = $this->entries->ofId(JournalEntryId::fromString($entryId));
        if (!$entry instanceof JournalEntry || !$entry->campaignId()->equals($campaign->id())) {
            throw JournalEntryNotFound::withId($entryId);
        }

        $content = $entry->content();
        if (!$content instanceof LikelihoodContent || $content->randomEvent() !== $oracleKey) {
            throw NotARandomEventQuestion::of($entryId, $oracleKey);
        }

        $session = $campaign->currentSession();
        $scene = $session?->currentScene();
        if (!$session instanceof Session || !$scene instanceof Scene) {
            throw NoCurrentScene::toRecordJournalEntry();
        }

        if ($entry->sessionNumber() !== $session->number() || $entry->sceneNumber() !== $scene->number()) {
            throw NotARandomEventQuestion::notInCurrentScene($entryId);
        }

        foreach ($this->entries->ofCampaign($campaign->id()) as $recorded) {
            if ($recorded->content() instanceof OracleTableContent && $recorded->content()->forEntry() === $entryId) {
                throw RandomEventAlreadyRolled::forEntry($entryId);
            }
        }
    }

    /**
     * The results a note attaches, checked before anything is recorded: each is an entry of this
     * campaign, recorded in the campaign's current session and scene, of a kind that can be
     * attached (a roll, an oracle table result, a likelihood answer or a check) and not the result
     * of a random event, which belongs to its question. Only the given entries are loaded.
     * (Duplicates and the maximum are the note's own rules.).
     *
     * @param list<string> $entryIds
     *
     * @throws InvalidNoteAttachment
     * @throws NoCurrentScene        when the campaign has no current scene to record the note in
     */
    public function assertAttachable(array $entryIds, Campaign $campaign): void
    {
        if ([] === $entryIds) {
            return;
        }

        $session = $campaign->currentSession();
        $scene = $session?->currentScene();
        if (!$session instanceof Session || !$scene instanceof Scene) {
            throw NoCurrentScene::toRecordJournalEntry();
        }

        foreach ($entryIds as $entryId) {
            $entry = $this->entries->ofId(JournalEntryId::fromString($entryId));
            if (!$entry instanceof JournalEntry || !$entry->campaignId()->equals($campaign->id())) {
                throw InvalidNoteAttachment::notInCampaign($entryId);
            }

            if ($entry->sessionNumber() !== $session->number() || $entry->sceneNumber() !== $scene->number()) {
                throw InvalidNoteAttachment::anotherScene($entryId);
            }

            $content = $entry->content();
            if (!\in_array($content->kind(), self::ATTACHABLE_KINDS, true)) {
                throw InvalidNoteAttachment::notAttachableKind($entryId, $content->kind());
            }

            if ($content instanceof OracleTableContent && null !== $content->forEntry()) {
                throw InvalidNoteAttachment::randomEvent($entryId);
            }
        }
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
