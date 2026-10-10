<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

/**
 * One entry of a FlowRun's history: what happened, when, and its details by name.
 */
final readonly class FlowRunHistoryEntry
{
    /**
     * @param array<string, int|string|null> $details
     */
    private function __construct(
        public FlowRunEvent $event,
        public \DateTimeImmutable $at,
        public array $details = [],
    ) {
    }

    public static function skip(\DateTimeImmutable $at, string $step, ScenePart $part): self
    {
        return new self(FlowRunEvent::Skip, $at, ['step' => $step, 'part' => $part->value]);
    }

    /**
     * @param string $reason "finished" (a once phase played its scenes), "moveOn" or "endPhase"
     */
    public static function phaseEnded(\DateTimeImmutable $at, string $phase, string $reason): self
    {
        return new self(FlowRunEvent::PhaseEnded, $at, ['phase' => $phase, 'reason' => $reason]);
    }

    public static function completed(\DateTimeImmutable $at): self
    {
        return new self(FlowRunEvent::Completed, $at);
    }
}
