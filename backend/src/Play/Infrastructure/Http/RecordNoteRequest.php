<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Journal\NoteContent;
use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/campaigns/{campaignId}/journal/notes`. Documents the contract only: the
 * controller reads the fields from the request.
 */
#[OA\Schema(required: ['text'])]
final readonly class RecordNoteRequest
{
    public function __construct(
        #[OA\Property(description: 'Trimmed; not blank.', example: 'The gate is open.', maxLength: NoteContent::MAX_LENGTH)]
        public string $text,
        /** @var ?list<string> */
        #[OA\Property(description: 'Ids of the journal entries of the current scene the note attaches, in the order shown: rolls, oracle table results, likelihood answers and checks, not a random event result; at most 20, none twice. Missing or null attaches nothing.', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), nullable: true, maxItems: NoteContent::MAX_ATTACHMENTS)]
        public ?array $attachments,
    ) {
    }
}
