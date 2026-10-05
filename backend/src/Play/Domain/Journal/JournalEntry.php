<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\NoCurrentScene;

/**
 * One immutable record of a campaign's journal: a note or the result of a roll or an oracle,
 * recorded in the campaign's current session and scene.
 */
final readonly class JournalEntry
{
    private function __construct(
        private JournalEntryId $id,
        private CampaignId $campaignId,
        private int $sessionNumber,
        private int $sceneNumber,
        private \DateTimeImmutable $recordedAt,
        private JournalEntryContent $content,
    ) {
    }

    /**
     * Records the content in the campaign's current session and scene.
     *
     * @throws NoCurrentScene when the campaign has no session, or its current session has no scene
     */
    public static function record(JournalEntryId $id, Campaign $campaign, JournalEntryContent $content, \DateTimeImmutable $recordedAt): self
    {
        $session = $campaign->currentSession();
        $scene = $session?->currentScene();
        if (!$session instanceof \App\Play\Domain\Campaign\Session || !$scene instanceof \App\Play\Domain\Campaign\Scene) {
            throw NoCurrentScene::toRecordJournalEntry();
        }

        return new self($id, $campaign->id(), $session->number(), $scene->number(), $recordedAt, $content);
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
    ): self {
        return new self($id, $campaignId, $sessionNumber, $sceneNumber, $recordedAt, $content);
    }

    public function id(): JournalEntryId
    {
        return $this->id;
    }

    public function campaignId(): CampaignId
    {
        return $this->campaignId;
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
}
