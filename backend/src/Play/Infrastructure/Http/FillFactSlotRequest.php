<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * The body to fill a Fact slot of a campaign, or fill it again. Send exactly one of the three
 * values; the slot's type decides which one fits. Documents the contract only: the controller
 * reads the body from the request.
 */
#[OA\Schema]
final readonly class FillFactSlotRequest
{
    public function __construct(
        #[OA\Property(description: 'For a "text" slot: 1 to 2000 characters once trimmed.', example: 'Ashford', nullable: true)]
        public ?string $text,
        #[OA\Property(description: 'For an "npc" or "thread" slot: the id of an NPC or Thread the campaign holds (closed ones too).', format: 'uuid', nullable: true)]
        public ?string $subject,
        #[OA\Property(description: 'For an "npc" or "thread" slot: the name of a new open NPC or Thread (weight 1, no tags) to create and fill the slot with.', example: 'Valen Vane', nullable: true)]
        public ?string $name,
    ) {
    }
}
