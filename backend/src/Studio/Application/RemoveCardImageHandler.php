<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\CommandHandler;
use App\Studio\Domain\GameSystem\GameSystemNotFound;

final readonly class RemoveCardImageHandler implements CommandHandler
{
    public function __construct(
        private OwnedGameSystems $owned,
        private CardImages $images,
    ) {
    }

    /**
     * @throws GameSystemNotFound when the user has no such GameSystem
     */
    public function __invoke(RemoveCardImage $command): void
    {
        $this->images->remove($this->owned->get($command->ownerId, $command->key)->id(), $command->slot);
    }
}
