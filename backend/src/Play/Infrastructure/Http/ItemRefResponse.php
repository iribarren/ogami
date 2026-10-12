<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * An NPC or Thread by id and name: a member of a Scene's cast, a candidate of a pick step or an
 * item a pick recorded. The name is the campaign's name now, except in a journal entry, which
 * keeps the name at pick time.
 */
#[OA\Schema(required: ['id', 'name'])]
final readonly class ItemRefResponse
{
    private function __construct(
        #[OA\Property(description: 'The id of the NPC or Thread.', example: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b')]
        public string $id,
        #[OA\Property(example: 'Vega')]
        public string $name,
    ) {
    }

    public static function of(string $id, string $name): self
    {
        return new self($id, $name);
    }
}
