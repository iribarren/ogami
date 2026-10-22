<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Query;

/**
 * Validates the owner's draft as a release (ReleaseContent::errors, fromArray), without publishing it.
 *
 * @implements Query<GameSystemValidation>
 */
final readonly class ValidateGameSystemDraft implements Query
{
    public function __construct(
        public string $ownerId,
        public string $key,
    ) {
    }
}
