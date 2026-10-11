<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\GameSystem\Flow\Step;

/**
 * The step a FlowRun waits on: its kind, the step of the pinned release (key, mandatory and the
 * fields of its kind, such as options, dice, oracle and likelihood, or table) and its title, prompt
 * and tip with their placeholders rendered. A mandatory step has no Skip.
 */
final readonly class FlowStepView
{
    public function __construct(
        public StepKind $kind,
        public Step $step,
        public string $title,
        public ?string $prompt,
        public ?string $tip,
    ) {
    }
}
