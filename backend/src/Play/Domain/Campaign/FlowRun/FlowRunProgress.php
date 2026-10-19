<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Hook;

/**
 * Where a FlowRun stands: Act › Phase (its key and name) › Scene Type › part · step n/m (the Scene Type only in a
 * scene, the part in a scene or, as the hook, in a hook; the step numbers only on a step;
 * condition steps, which never wait, are not counted). It also carries the phase's description and the
 * number of the scene or hook Scene the FlowRun is in (null at the scene pick, while waiting for a
 * session and once the Flow is complete).
 */
final readonly class FlowRunProgress
{
    public function __construct(
        public ?string $act,
        public string $phaseKey,
        public string $phase,
        public ?string $sceneType,
        public ScenePart|Hook|null $part,
        public ?int $stepNumber,
        public ?int $stepCount,
        public ?string $phaseDescription = null,
        public ?int $sceneNumber = null,
    ) {
    }
}
