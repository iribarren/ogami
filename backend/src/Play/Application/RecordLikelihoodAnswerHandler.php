<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\NoCurrentScene;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnknownGameSystemOracle;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Play\Domain\Journal\InvalidJournalEntryContent;
use App\Play\Domain\Journal\InvalidJournalEntryId;
use App\Play\Domain\Journal\JournalEntryAlreadyExists;
use App\Play\Domain\Journal\LikelihoodContent;
use App\Randomness\Domain\Oracle\InvalidLikelihoodOracle;
use App\Randomness\Domain\RandomNumberGenerator;
use App\Shared\Application\Bus\CommandHandler;

final readonly class RecordLikelihoodAnswerHandler implements CommandHandler
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
     * @throws UnknownGameSystemOracle         when the pinned release has no likelihood oracle with this key
     * @throws InvalidLikelihoodOracle         when the likelihood level is unknown, or the chaos factor is out of range or not expected
     * @throws InvalidJournalEntryContent      when the question is too long
     * @throws GameSystemReleaseNotFound       when the pinned release can no longer be read
     * @throws UnsupportedReleaseSchemaVersion
     * @throws InvalidGameSystemRelease
     * @throws NoCurrentScene
     */
    public function __invoke(RecordLikelihoodAnswer $command): void
    {
        $campaign = $this->journal->campaign($command->campaignId, $command->userId);
        $oracle = $this->journal->pinnedSnapshot($campaign)->likelihoodOracle($command->oracleKey);
        $answer = $oracle->oracle()->ask($command->likelihood, $command->chaosFactor, $this->random);
        $content = LikelihoodContent::fromAnswer($oracle->key(), $oracle->name(), $command->question, $answer);

        $this->journal->record($command->entryId, $campaign, $content);
    }
}
