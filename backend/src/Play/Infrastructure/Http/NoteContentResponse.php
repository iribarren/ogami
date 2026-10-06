<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Journal\NoteContent;
use OpenApi\Attributes as OA;

/**
 * A note written by the player.
 */
#[OA\Schema(required: ['kind', 'text'])]
final readonly class NoteContentResponse
{
    private function __construct(
        #[OA\Property(enum: [NoteContent::KIND])]
        public string $kind,
        #[OA\Property(example: 'The gate is open.', maxLength: NoteContent::MAX_LENGTH)]
        public string $text,
    ) {
    }

    public static function of(NoteContent $content): self
    {
        return new self(NoteContent::KIND, $content->text());
    }
}
