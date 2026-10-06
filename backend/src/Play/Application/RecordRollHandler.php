<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\NoCurrentScene;
use App\Play\Domain\Journal\InvalidJournalEntryId;
use App\Play\Domain\Journal\JournalEntryAlreadyExists;
use App\Play\Domain\Journal\RollContent;
use App\Randomness\Domain\DiceExpression;
use App\Randomness\Domain\InvalidDiceExpression;
use App\Randomness\Domain\RandomNumberGenerator;
use App\Shared\Application\Bus\CommandHandler;

final readonly class RecordRollHandler implements CommandHandler
{
    public function __construct(
        private CampaignJournal $journal,
        private RandomNumberGenerator $random,
    ) {
    }

    /**
     * @throws CampaignNotFound
     * @throws InvalidJournalEntryId
     * @throws JournalEntryAlreadyExists
     * @throws InvalidDiceExpression     when the expression cannot be parsed or rolled
     * @throws NoCurrentScene
     */
    public function __invoke(RecordRoll $command): void
    {
        $campaign = $this->journal->campaign($command->campaignId, $command->userId);
        $roll = DiceExpression::fromString($command->expression)->roll($this->random);

        $this->journal->record($command->entryId, $campaign, RollContent::fromRoll($roll));
    }
}
