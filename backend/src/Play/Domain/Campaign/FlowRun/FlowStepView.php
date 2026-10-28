<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\GameSystem\FactSlot;
use App\Play\Domain\GameSystem\Flow\Step;
use App\Play\Domain\GameSystem\GearEntry;

/**
 * The step a FlowRun waits on: its kind, the step of the pinned release (key, mandatory and the
 * fields of its kind, such as options, dice, oracle and likelihood, or table) and its title, prompt
 * and tip with their placeholders rendered. A mandatory step has no Skip. A pick step also shows
 * what it picks from (PickView). "fills" are the Fact slots any of its Effects may fill, in the
 * release's slot order. A check step whose roll waits for Keep or Push (ADR 0041) shows it as
 * "pending". A table step shows the table it rolls now, which depends on the current Scene Type
 * (ADR 0042). A shop step shows the entries it has on sale, in catalog order (ADR 0045).
 */
final readonly class FlowStepView
{
    /**
     * @param list<FactSlot>   $fills
     * @param ?list<GearEntry> $onSale
     */
    public function __construct(
        public StepKind $kind,
        public Step $step,
        public string $title,
        public ?string $prompt,
        public ?string $tip,
        public ?PickView $pick = null,
        public array $fills = [],
        public ?CheckView $check = null,
        public ?PendingCheck $pending = null,
        public ?string $table = null,
        public ?array $onSale = null,
    ) {
    }
}
