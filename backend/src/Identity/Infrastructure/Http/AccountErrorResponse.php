<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of every error of the public account endpoints: a message for people and a code for the client.
 */
#[OA\Schema(required: ['error', 'code'])]
final readonly class AccountErrorResponse
{
    private function __construct(
        #[OA\Property(description: 'A human-readable message; never says whether an email has an account.')]
        public string $error,
        #[OA\Property(description: 'Why the request failed; the client branches on this, never on the message.', enum: AccountErrorCode::class)]
        public AccountErrorCode $code,
    ) {
    }

    public static function with(AccountErrorCode $code, string $message): self
    {
        return new self($message, $code);
    }
}
