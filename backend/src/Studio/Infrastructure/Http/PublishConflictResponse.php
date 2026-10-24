<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of the publish 409: a message for people and a code for the client.
 */
#[OA\Schema(required: ['error', 'code'])]
final readonly class PublishConflictResponse
{
    private function __construct(
        #[OA\Property(description: 'A human-readable message.')]
        public string $error,
        #[OA\Property(description: 'Why nothing was published; the client branches on this, never on the message.', enum: PublishConflictCode::class)]
        public PublishConflictCode $code,
    ) {
    }

    public static function with(PublishConflictCode $code, string $message): self
    {
        return new self($message, $code);
    }
}
