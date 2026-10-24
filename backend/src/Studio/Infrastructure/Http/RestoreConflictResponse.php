<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of the restore 409: a message for people and a code for the client.
 */
#[OA\Schema(required: ['error', 'code'])]
final readonly class RestoreConflictResponse
{
    private function __construct(
        #[OA\Property(description: 'A human-readable message.')]
        public string $error,
        #[OA\Property(description: 'Why the draft was not replaced; the client branches on this, never on the message.', enum: RestoreConflictCode::class)]
        public RestoreConflictCode $code,
    ) {
    }

    public static function with(RestoreConflictCode $code, string $message): self
    {
        return new self($message, $code);
    }
}
