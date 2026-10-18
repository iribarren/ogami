<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Upgrade;

/**
 * Which Flow the campaign plays after an upgrade. Guidance always starts again: restarted is true
 * whenever the campaign had a FlowRun or gets one. A null Flow is free play.
 */
final readonly class FlowChange
{
    public function __construct(
        public ?string $fromFlowKey,
        public ?string $toFlowKey,
        public bool $restarted,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['fromFlowKey' => $this->fromFlowKey, 'toFlowKey' => $this->toFlowKey, 'restarted' => $this->restarted];
    }
}
