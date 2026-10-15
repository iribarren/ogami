<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\CommandHandler;
use App\Studio\Domain\GameSystem\GameSystemManagedInStudio;
use App\Studio\Domain\GameSystem\GameSystemRepository;
use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\ReleaseId;

final readonly class PublishGameSystemReleaseHandler implements CommandHandler
{
    public function __construct(
        private ReleasePublisher $publisher,
        private GameSystemRepository $gameSystems,
    ) {
    }

    /**
     * @throws InvalidReleaseContent     naming the path of the first offending value; nothing is published
     * @throws GameSystemManagedInStudio when a Studio GameSystem has the key (also with onlyIfChanged); nothing is published
     */
    public function __invoke(PublishGameSystemRelease $command): void
    {
        $id = ReleaseId::fromString($command->releaseId);
        $content = ReleaseContent::fromArray($command->content);

        if ($this->gameSystems->ofKey($content->gameSystemKey()) instanceof \App\Studio\Domain\GameSystem\GameSystem) {
            throw GameSystemManagedInStudio::withKey($content->gameSystemKey());
        }

        $this->publisher->publish($id, $content, null, $command->onlyIfChanged);
    }
}
