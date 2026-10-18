<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\CommandHandler;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\GameSystem\LibraryImport;
use App\Studio\Domain\GameSystem\LibraryPackRefuses;
use App\Studio\Domain\Release\GameSystemDraftUnchanged;
use App\Studio\Domain\Release\GameSystemReleaseAlreadyExists;
use App\Studio\Domain\Release\GameSystemReleaseRepository;
use App\Studio\Domain\Release\InvalidGameSystemRelease;
use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\ReleaseId;

final readonly class PublishGameSystemDraftHandler implements CommandHandler
{
    public function __construct(
        private OwnedGameSystems $owned,
        private ReleasePublisher $publisher,
        private GameSystemReleaseRepository $releases,
    ) {
    }

    /**
     * @throws GameSystemNotFound             when the user has no such GameSystem
     * @throws LibraryPackRefuses             when the GameSystem is a library pack, which is never published
     * @throws InvalidReleaseContent          naming the path of the first offending value in the draft; nothing is published
     * @throws InvalidGameSystemRelease       when the notes are blank or too long; nothing is published
     * @throws GameSystemDraftUnchanged       when the draft has the content of the latest release
     * @throws GameSystemReleaseAlreadyExists when another publish took the version first
     */
    public function __invoke(PublishGameSystemDraft $command): void
    {
        $gameSystem = $this->owned->get($command->ownerId, $command->key);
        if ($gameSystem->isLibraryPack()) {
            throw LibraryPackRefuses::to('be published', $command->key);
        }

        $content = ReleaseContent::fromArray($gameSystem->document());

        if (!$this->publisher->publish(ReleaseId::fromString($command->releaseId), $content, $command->notes, true, array_map(static fn (LibraryImport $import): array => $import->toArray(), $gameSystem->libraryImports()))) {
            throw GameSystemDraftUnchanged::for($command->key, $this->releases->latestFor($command->key)?->version() ?? 0);
        }
    }
}
