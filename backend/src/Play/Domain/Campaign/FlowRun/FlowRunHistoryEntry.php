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
     * A Tracker edited by hand.
     */
    public static function trackerEdit(\DateTimeImmutable $at, string $tracker, int $from, int $to): self
    {
        return new self(FlowRunEvent::TrackerEdit, $at, ['tracker' => $tracker, 'from' => $from, 'to' => $to]);
    }

    /**
     * A scene's Scene Type switched by hand.
     *
     * @param int     $scene the scene's number in the session under way
     * @param ?string $from  the Scene Type key before, null for none
     */
    public static function sceneTypeSwitch(\DateTimeImmutable $at, int $scene, ?string $from, string $to): self
    {
        return new self(FlowRunEvent::SceneTypeSwitch, $at, ['scene' => $scene, 'from' => $from, 'to' => $to]);
    }

    public static function paused(\DateTimeImmutable $at): self
    {
        return new self(FlowRunEvent::Paused, $at);
    }

    public static function resumed(\DateTimeImmutable $at): self
    {
        return new self(FlowRunEvent::Resumed, $at);
    }

    /**
     * The guided scene is left unfinished: it is no longer the current scene.
     */
    public static function sceneAbandoned(\DateTimeImmutable $at, int $session, int $scene): self
    {
        return new self(FlowRunEvent::SceneAbandoned, $at, ['session' => $session, 'scene' => $scene]);
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
