<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\Characters\Character;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\InvalidCharacter;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\Campaign\Characters\UnknownCharacter;
use App\Play\Domain\Campaign\NoCurrentScene;
use App\Play\Domain\GameSystem\Check\Check;
use App\Play\Domain\GameSystem\Check\CheckDifficultyNotDeclared;
use App\Play\Domain\GameSystem\Check\CheckDifficultyOutOfRange;
use App\Play\Domain\GameSystem\Check\CheckEvaluationFailed;
use App\Play\Domain\GameSystem\Check\SituationalModifierOutOfRange;
use App\Play\Domain\GameSystem\Check\UnknownCheck;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Play\Domain\Journal\CheckContent;
use App\Play\Domain\Journal\InvalidJournalEntryId;
use App\Play\Domain\Journal\JournalEntryAlreadyExists;
use App\Randomness\Domain\RandomNumberGenerator;
use App\Shared\Application\Bus\CommandHandler;

final readonly class PerformCheckHandler implements CommandHandler
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
     * @throws UnknownCheck                    when the pinned release has no check with this key
     * @throws InvalidCharacter                when the Character id is blank
     * @throws UnknownCharacter                when the campaign holds no Character with this id
     * @throws CheckDifficultyNotDeclared      when a difficulty is sent for a check that declares none
     * @throws CheckDifficultyOutOfRange       when the difficulty is outside the min..max of the check
     * @throws SituationalModifierOutOfRange   when the Situational modifier is outside -100..100
     * @throws CheckEvaluationFailed           when the modifier or a band of the check cannot be evaluated for the Character
     * @throws GameSystemReleaseNotFound       when the pinned release can no longer be read
     * @throws UnsupportedReleaseSchemaVersion
     * @throws InvalidGameSystemRelease
     * @throws NoCurrentScene
     */
    public function __invoke(PerformCheck $command): void
    {
        $campaign = $this->journal->campaign($command->campaignId, $command->userId);
        $release = $this->journal->pinnedSnapshot($campaign);
        $check = $release->check($command->check) ?? throw UnknownCheck::withKey($release->gameSystemKey(), $release->releaseVersion(), $command->check);
        $characterId = CharacterId::fromString($command->character);
        // Any Character of the campaign can roll, in the Scene cast or not.
        $character = $campaign->character($characterId) ?? throw UnknownCharacter::withId($characterId);

        $result = $check->perform(
            $this->random,
            SheetValues::of($release->sheet(), $character->values(), $campaign->trackerValuesOf($release)),
            $command->difficulty,
            $command->situational,
        );

        $this->journal->record($command->entryId, $campaign, CheckContent::fromResult($character->id()->toString(), $character->name(), $check, $result));
    }
}
