<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Campaign\Upgrade\FlowChange;
use OpenApi\Attributes as OA;

/**
 * What the upgrade does to the campaign's guidance.
 */
#[OA\Schema(required: ['fromFlowKey', 'toFlowKey', 'restarted'])]
final readonly class UpgradeFlowChangeResponse
{
    private function __construct(
        #[OA\Property(description: 'The key of the Flow played now; null when played freely.', example: 'the-heist', nullable: true)]
        public ?string $fromFlowKey,
        #[OA\Property(description: 'The key of the Flow played after the upgrade: the same one when the target release has it, else its default Flow, else its first; null when played freely.', example: 'the-heist', nullable: true)]
        public ?string $toFlowKey,
        #[OA\Property(description: 'Whether guidance starts again at the first phase of the Flow (a guided scene open now goes on as a free scene).')]
        public bool $restarted,
    ) {
    }

    public static function fromChange(FlowChange $change): self
    {
        return new self($change->fromFlowKey, $change->toFlowKey, $change->restarted);
    }
}
