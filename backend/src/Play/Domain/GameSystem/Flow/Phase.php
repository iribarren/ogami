<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

/**
 * A Phase of a Flow: an optional Act label, an optional description (what the phase is for and when
 * it is done), its mode, its Scene selection and its Hooks, step
 * lists run around sessions, the phase and each scene (empty when the release omits them).
 */
final readonly class Phase
{
    public function __construct(
        public string $key,
        public string $name,
        public ?string $act,
        public PhaseMode $mode,
        public SceneSelection $selection,
        public ?string $description = null,
        public StepList $sessionOpening = new StepList(),
        public StepList $sessionClosing = new StepList(),
        public StepList $phaseOpening = new StepList(),
        public StepList $phaseClosing = new StepList(),
        public StepList $sceneOpening = new StepList(),
        public StepList $sceneClosing = new StepList(),
        public StepList $worldTurn = new StepList(),
    ) {
    }
}
