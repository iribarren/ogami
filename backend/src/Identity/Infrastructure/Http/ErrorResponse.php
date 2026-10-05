<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of an authentication or authorization error (401, 403, 415, 429).
 */
#[OA\Schema(required: ['error'])]
final readonly class ErrorResponse
{
    private function __construct(
        #[OA\Property(description: 'A human-readable message; never says whether an email exists.')]
        public string $error,
    ) {
    }

    public static function withMessage(string $message): self
    {
        return new self($message);
    }
}
