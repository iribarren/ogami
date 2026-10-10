<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

/**
 * What the player sees of a FlowRun: its status, where it stands, the step or scene pick it waits
 * on, whether "Move on" is offered, and the next step named across boundaries ("Closing: What
 * changed?", "Next scene: choose a scene type", "Next: Briefing", "Session Zero complete →
 * Adventure", "Flow complete").
 */
final readonly class FlowRunView
{
    /**
     * @param bool             $waitsForSession whether no session is under way, so nothing can be played yet
     * @param ?FlowRunProgress $progress        null once the Flow is complete
     * @param ?FlowStepView    $step            the current step; null at the scene pick and in open play
     * @param ?ScenePickView   $pick            the scene pick; null in a scene
     * @param bool             $canMoveOn       whether "Move on" ends the phase (a loop phase not ending yet, guided)
     * @param string           $next            the next step named
     */
    public function __construct(
        public FlowRunStatus $status,
        public bool $waitsForSession,
        public ?FlowRunProgress $progress,
        public ?FlowStepView $step,
        public ?ScenePickView $pick,
        public bool $canMoveOn,
        public string $next,
    ) {
    }
}
