<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\NoCurrentScene;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnknownGameSystemOracle;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Play\Domain\Journal\InvalidJournalEntryId;
use App\Play\Domain\Journal\JournalEntryAlreadyExists;
use App\Play\Domain\Journal\OracleTableContent;
use App\Randomness\Domain\RandomNumberGenerator;
use App\Shared\Application\Bus\CommandHandler;

final readonly class RecordOracleTableResultHandler implements CommandHandler
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
     * @throws UnknownGameSystemOracle         when the pinned release has no oracle table with this key
     * @throws GameSystemReleaseNotFound       when the pinned release can no longer be read
     * @throws UnsupportedReleaseSchemaVersion
     * @throws InvalidGameSystemRelease
     * @throws NoCurrentScene
     */
    public function __invoke(RecordOracleTableResult $command): void
    {
        $campaign = $this->journal->campaign($command->campaignId, $command->userId);
        $snapshot = $this->journal->pinnedSnapshot($campaign);
        $result = $snapshot->resolveOracleTable($command->oracleKey, $this->random);
        $content = OracleTableContent::fromResult($command->oracleKey, $snapshot->oracleTableNames()[$command->oracleKey], $result);

        $this->journal->record($command->entryId, $campaign, $content);
    }
}
