<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\NoCurrentScene;
use App\Play\Domain\Journal\InvalidJournalEntryContent;
use App\Play\Domain\Journal\InvalidJournalEntryId;
use App\Play\Domain\Journal\InvalidNoteAttachment;
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
     * @throws InvalidNoteAttachment      when an attached result breaks a rule (the note records nothing)
     * @throws NoCurrentScene
     */
    public function __invoke(RecordNote $command): void
    {
        $campaign = $this->journal->campaign($command->campaignId, $command->userId);

        $content = NoteContent::of($command->text, $command->attachments);
        $this->journal->assertAttachable($content->attachments(), $campaign);

        $this->journal->record($command->entryId, $campaign, $content);
    }
}
