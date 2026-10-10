<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\NoCurrentScene;

/**
 * One immutable record of a campaign's journal: a note, the result of a roll or an oracle, or a
 * choice, recorded in the campaign's current session and scene. An entry recorded by a Flow step
 * keeps a snapshot of that step.
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
