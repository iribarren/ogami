<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Journal\NoteContent;
use OpenApi\Attributes as OA;

/**
 * A note written by the player.
 */
#[OA\Schema(required: ['kind', 'text', 'attachments'])]
final readonly class NoteContentResponse
{
    private function __construct(
        #[OA\Property(enum: [NoteContent::KIND])]
        public string $kind,
        #[OA\Property(example: 'The gate is open.', maxLength: NoteContent::MAX_LENGTH)]
        public string $text,
        /** @var list<string> */
        #[OA\Property(description: 'Ids of the journal entries the note attaches, in the order picked; empty when none.', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))]
        public array $attachments,
    ) {
    }

    public static function of(NoteContent $content): self
    {
        return new self(NoteContent::KIND, $content->text(), $content->attachments());
    }
}
