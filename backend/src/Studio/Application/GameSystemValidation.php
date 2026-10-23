<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * The outcome of validating a draft as a release: every error with its path when invalid (in the
 * order publishing meets them, so the first is the one publishing refuses with), the authoring
 * warnings when valid.
 */
final readonly class GameSystemValidation
{
    /**
     * @param list<GameSystemValidationError> $errors   empty when valid
     * @param list<string>                    $warnings each starting with the path it is about
     */
    public function __construct(
        public bool $valid,
        public array $errors,
        public array $warnings,
    ) {
    }
}
