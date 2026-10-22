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

        $errors = ReleaseContent::errors($document);
        if ([] === $errors) {
            try {
                return new GameSystemValidation(true, [], ReleaseContent::fromArray($document)->warnings());
            } catch (InvalidReleaseContent $rejection) {
                // errors() and fromArray() agree; should they ever not, the draft is invalid, never a 500.
                $errors = [$rejection];
            }
        }

        return new GameSystemValidation(false, array_map(static fn (InvalidReleaseContent $error): GameSystemValidationError => new GameSystemValidationError($error->path(), $error->reason()), $errors), []);
    }
}
