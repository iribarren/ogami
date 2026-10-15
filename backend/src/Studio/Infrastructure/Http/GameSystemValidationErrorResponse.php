<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * The first error of an invalid draft.
 */
#[OA\Schema(required: ['message'])]
final readonly class GameSystemValidationErrorResponse
{
    private function __construct(
        #[OA\Property(description: 'Starts with the path of the offending part of the draft.', example: 'oracles: must be an object')]
        public string $message,
    ) {
    }

    public static function withMessage(string $message): self
    {
        return new self($message);
    }
}
