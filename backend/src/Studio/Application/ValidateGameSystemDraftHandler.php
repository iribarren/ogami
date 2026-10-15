<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\QueryHandler;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;

final readonly class ValidateGameSystemDraftHandler implements QueryHandler
{
    public function __construct(
        private OwnedGameSystems $owned,
    ) {
    }

    /**
     * @throws GameSystemNotFound when the user has no such GameSystem
     */
    public function __invoke(ValidateGameSystemDraft $query): GameSystemValidation
    {
        $document = $this->owned->get($query->ownerId, $query->key)->document();

        try {
            return new GameSystemValidation(true, null, ReleaseContent::fromArray($document)->warnings());
        } catch (InvalidReleaseContent $rejection) {
            return new GameSystemValidation(false, $rejection->getMessage(), []);
        }
    }
}
