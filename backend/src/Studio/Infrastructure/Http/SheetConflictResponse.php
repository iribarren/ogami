<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of a point budget route's 409: a message for people and a code for the client.
 */
#[OA\Schema(required: ['error', 'code'])]
final readonly class SheetConflictResponse
{
    private function __construct(
        #[OA\Property(description: 'A human-readable message.')]
        public string $error,
        #[OA\Property(description: 'Why nothing changed; the client branches on this, never on the message.', enum: SheetConflictCode::class)]
        public SheetConflictCode $code,
    ) {
    }

    public static function with(SheetConflictCode $code, string $message): self
    {
        return new self($message, $code);
    }
}
