<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Hook;

/**
 * Where a FlowRun stands: Act › Phase › Scene Type › part · step n/m (the Scene Type only in a
 * scene, the part in a scene or, as the hook, in a hook; the step numbers only on a step;
 * condition steps, which never wait, are not counted).
 */
final readonly class FlowRunProgress
{
    public function __construct(
        public ?string $act,
        public string $phase,
        public ?string $sceneType,
        public ScenePart|Hook|null $part,
        public ?int $stepNumber,
        public ?int $stepCount,
    ) {
    }
}
