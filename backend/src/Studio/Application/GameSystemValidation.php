<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * The outcome of validating a draft as a release: the first error with its path when invalid
 * (the validator is fail-fast), the authoring warnings when valid.
 */
final readonly class GameSystemValidation
{
    /**
     * @param list<string> $warnings each starting with the path it is about
     */
    public function __construct(
        public bool $valid,
        public ?string $error,
        public array $warnings,
    ) {
    }
}
