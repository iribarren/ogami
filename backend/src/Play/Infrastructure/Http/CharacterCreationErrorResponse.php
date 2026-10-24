<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of a 422 from adding a Character: a message for people and, when a rule of structured
 * character creation was broken, a code for the client (ADR 0037, ADR 0040). Any other 422 has no code.
 */
#[OA\Schema(required: ['error'])]
final readonly class CharacterCreationErrorResponse
{
    private function __construct(
        #[OA\Property(description: 'A human-readable message.')]
        public string $error,
        #[OA\Property(description: 'Which rule of structured character creation was broken; the client branches on this, never on the message. Absent for any other refusal.')]
        public ?CharacterCreationErrorCode $code,
    ) {
    }

    public static function with(CharacterCreationErrorCode $code, string $message): self
    {
        return new self($message, $code);
    }

    public static function withoutCode(string $message): self
    {
        return new self($message, null);
    }

    /**
     * @return array{error: string, code?: string}
     */
    public function toArray(): array
    {
        return $this->code instanceof CharacterCreationErrorCode ? ['error' => $this->error, 'code' => $this->code->value] : ['error' => $this->error];
    }
}
