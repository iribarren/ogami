<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * The Flow step that recorded a journal entry, as the player saw it.
 */
#[OA\Schema(required: ['key', 'title', 'prompt'])]
final readonly class JournalFlowStepResponse
{
    private function __construct(
        #[OA\Property(example: 'slip-past')]
        public string $key,
        #[OA\Property(example: 'Who is on the crew?')]
        public string $title,
        #[OA\Property(example: 'Describe your first crew member.', nullable: true)]
        public ?string $prompt,
    ) {
    }

    /**
     * @param array{key: string, title: string, prompt: ?string} $flowStep the shape stored with the entry
     */
    public static function of(array $flowStep): self
    {
        return new self($flowStep['key'], $flowStep['title'], $flowStep['prompt']);
    }
}
