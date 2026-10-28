<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of a 422 from completing a step: a message for people and, when the step broke a rule of
 * the sheet given the Character's values, a code for the client (ADR 0037, ADR 0045). Any other 422
 * has no code.
 */
#[OA\Schema(required: ['error'])]
final readonly class FlowRunErrorResponse
{
    private function __construct(
        #[OA\Property(description: 'A human-readable message.')]
        public string $error,
        #[OA\Property(description: 'Which rule of the sheet the step broke; the client branches on this, never on the message. Absent for any other refusal.')]
        public ?FlowRunErrorCode $code,
    ) {
    }

    public static function with(FlowRunErrorCode $code, string $message): self
    {
        return new self($message, $code);
    }

    /**
     * @return array{error: string, code?: string}
     */
    public function toArray(): array
    {
        return $this->code instanceof FlowRunErrorCode ? ['error' => $this->error, 'code' => $this->code->value] : ['error' => $this->error];
    }
}
