<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Campaign\FlowRun\PendingCheck;
use OpenApi\Attributes as OA;

/**
 * The roll a check step waits on until the player keeps or pushes it (ADR 0041): the journal entry
 * that holds it and the Character who rolled.
 */
#[OA\Schema(required: ['entryId', 'characterId'])]
final readonly class FlowStepPendingResponse
{
    public function __construct(
        #[OA\Property(description: 'The journal entry of the roll, as the journal lists it.', example: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b')]
        public string $entryId,
        #[OA\Property(description: 'The Character who rolled, who pays the cost of a push.', example: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c')]
        public string $characterId,
    ) {
    }

    public static function fromPending(PendingCheck $pending): self
    {
        return new self($pending->entryId, $pending->characterId);
    }
}
