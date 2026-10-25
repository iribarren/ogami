<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\NoCurrentScene;

/**
 * One record of a campaign's journal: a note, the result of a roll or an oracle, or a choice,
 * recorded in the campaign's current session and scene. An entry recorded by a Flow step keeps a
 * snapshot of that step.
 *
 * An entry is immutable but for one change: the push of a pool check completes its content once
 * (ADR 0041, see pushedWith()). Nothing else about an entry ever changes.
 *
 * Ids are kept as strings (like Campaign), so an adapter can map them as plain columns.
 */
final readonly class JournalEntry
{
    private function __construct(
        private string $id,
        private string $campaignId,
        private int $sessionNumber,
        private int $sceneNumber,
        private \DateTimeImmutable $recordedAt,
        private JournalEntryContent $content,
        private ?FlowStepSnapshot $flowStep,
    ) {
    }

    /**
     * Records the content in the campaign's current session and scene.
     *
     * @param ?FlowStepSnapshot $flowStep the Flow step recording it, null when the player records it freely
     *
     * @throws NoCurrentScene when the campaign has no session, or its current session has no scene
     */
    public static function record(JournalEntryId $id, Campaign $campaign, JournalEntryContent $content, \DateTimeImmutable $recordedAt, ?FlowStepSnapshot $flowStep = null): self
    {
        $session = $campaign->currentSession();
        $scene = $session?->currentScene();
        if (!$session instanceof \App\Play\Domain\Campaign\Session || !$scene instanceof \App\Play\Domain\Campaign\Scene) {
            throw NoCurrentScene::toRecordJournalEntry();
        }

        return new self($id->toString(), $campaign->id()->toString(), $session->number(), $scene->number(), $recordedAt, $content, $flowStep);
    }

    /**
     * Another entry recorded with this one, in the same session and scene, at the same time and by
     * the same Flow step (what a step's sheet Effects changed, after the step's own entry).
     */
    public function followedBy(JournalEntryId $id, JournalEntryContent $content): self
    {
        return new self($id->toString(), $this->campaignId, $this->sessionNumber, $this->sceneNumber, $this->recordedAt, $content, $this->flowStep);
    }

    /**
     * The same entry once its pool check was pushed: the only change an entry's content can take.
     * It is a copy; the repository keeps it with JournalEntryRepository::savePush().
     *
     * @param CheckContent $pushed the content of this entry as CheckContent::pushed() returned it
     *
     * @throws CheckAlreadyPushed when this entry's check was pushed already
     * @throws \LogicException    when this entry is not a pool check, or the content is not its pushed content
     */
    public function pushedWith(CheckContent $pushed): self
    {
        $current = $this->content;
        if (!$current instanceof CheckContent || !$current->pool() instanceof CheckPoolContent) {
            throw new \LogicException(\sprintf('Journal entry "%s" is not a pool check, only that can be pushed.', $this->id));
        }

        if ($current->pool()->push instanceof CheckPushContent) {
            throw CheckAlreadyPushed::entry($this->id());
        }

        if (!$pushed->pool()?->push instanceof CheckPushContent || $pushed->checkKey() !== $current->checkKey() || $pushed->characterId() !== $current->characterId()) {
            throw new \LogicException(\sprintf('The content is not the pushed content of journal entry "%s".', $this->id));
        }

        return new self($this->id, $this->campaignId, $this->sessionNumber, $this->sceneNumber, $this->recordedAt, $pushed, $this->flowStep);
    }

    /**
     * Rebuilds a stored entry, e.g. from persistence. No rule is checked again.
     */
    public static function reconstitute(
        JournalEntryId $id,
        CampaignId $campaignId,
        int $sessionNumber,
        int $sceneNumber,
        \DateTimeImmutable $recordedAt,
        JournalEntryContent $content,
        ?FlowStepSnapshot $flowStep = null,
    ): self {
        return new self($id->toString(), $campaignId->toString(), $sessionNumber, $sceneNumber, $recordedAt, $content, $flowStep);
    }

    public function id(): JournalEntryId
    {
        return JournalEntryId::fromString($this->id);
    }

    public function campaignId(): CampaignId
    {
        return CampaignId::fromString($this->campaignId);
    }

    public function sessionNumber(): int
    {
        return $this->sessionNumber;
    }

    public function sceneNumber(): int
    {
        return $this->sceneNumber;
    }

    public function recordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function content(): JournalEntryContent
    {
        return $this->content;
    }

    /**
     * The Flow step that recorded the entry; null when the player recorded it freely.
     */
    public function flowStep(): ?FlowStepSnapshot
    {
        return $this->flowStep;
    }
}
