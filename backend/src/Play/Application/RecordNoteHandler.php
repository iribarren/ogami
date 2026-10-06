<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\NoCurrentScene;
use App\Play\Domain\Journal\InvalidJournalEntryContent;
use App\Play\Domain\Journal\InvalidJournalEntryId;
use App\Play\Domain\Journal\JournalEntryAlreadyExists;
use App\Play\Domain\Journal\NoteContent;
use App\Shared\Application\Bus\CommandHandler;

final readonly class RecordNoteHandler implements CommandHandler
{
    public function __construct(
        private CampaignJournal $journal,
    ) {
    }

    /**
     * @throws CampaignNotFound
     * @throws InvalidJournalEntryId
     * @throws JournalEntryAlreadyExists
     * @throws InvalidJournalEntryContent when the text is blank or too long
     * @throws NoCurrentScene
     */
    public function __invoke(RecordNote $command): void
    {
        $campaign = $this->journal->campaign($command->campaignId, $command->userId);

        $this->journal->record($command->entryId, $campaign, NoteContent::of($command->text));
    }
}
