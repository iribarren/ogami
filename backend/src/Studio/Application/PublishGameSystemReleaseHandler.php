<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\CommandHandler;
use App\Studio\Domain\Release\GameSystemRelease;
use App\Studio\Domain\Release\GameSystemReleaseRepository;
use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\ReleaseId;

final readonly class PublishGameSystemReleaseHandler implements CommandHandler
{
    public function __construct(
        private GameSystemReleaseRepository $releases,
        private Clock $clock,
    ) {
    }

    /**
     * @throws InvalidReleaseContent naming the path of the first offending value; nothing is published
     */
    public function __invoke(PublishGameSystemRelease $command): void
    {
        $id = ReleaseId::fromString($command->releaseId);
        $content = ReleaseContent::fromArray($command->content);

        $latest = $this->releases->latestFor($content->gameSystemKey());
        if ($command->onlyIfChanged && $latest?->contentHash() === $content->hash()) {
            return;
        }

        $this->releases->add(GameSystemRelease::publish(
            $id,
            $content,
            ($latest?->version() ?? 0) + 1,
            $this->clock->now(),
        ));
    }
}
