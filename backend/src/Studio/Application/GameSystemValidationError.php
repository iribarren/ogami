<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * One error of an invalid draft: the path of the offending value and the reason alone.
 */
final readonly class GameSystemValidationError
{
    public function __construct(
        public string $path,
        public string $message,
    ) {
    }
}
