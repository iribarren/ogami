<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\GameSystem\Flow\Step;

/**
 * The step a FlowRun waits on: its kind and the step of the pinned release (key, title, prompt,
 * tip, mandatory and the fields of its kind, such as options, dice, oracle and likelihood, or
 * table). A mandatory step has no Skip.
 */
final readonly class FlowStepView
{
    public function __construct(
        public StepKind $kind,
        public Step $step,
    ) {
    }
}
