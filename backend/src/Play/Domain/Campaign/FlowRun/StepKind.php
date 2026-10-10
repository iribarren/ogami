<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\GameSystem\Flow\ChoiceStep;
use App\Play\Domain\GameSystem\Flow\ConditionStep;
use App\Play\Domain\GameSystem\Flow\OracleStep;
use App\Play\Domain\GameSystem\Flow\PromptStep;
use App\Play\Domain\GameSystem\Flow\RollStep;
use App\Play\Domain\GameSystem\Flow\Step;
use App\Play\Domain\GameSystem\Flow\TableStep;

enum StepKind: string
{
    case Prompt = 'prompt';
    case Oracle = 'oracle';
    case Table = 'table';
    case Roll = 'roll';
    case Choice = 'choice';
    case Condition = 'condition';

    public static function of(Step $step): self
    {
        return match (true) {
            $step instanceof PromptStep => self::Prompt,
            $step instanceof OracleStep => self::Oracle,
            $step instanceof TableStep => self::Table,
            $step instanceof RollStep => self::Roll,
            $step instanceof ChoiceStep => self::Choice,
            $step instanceof ConditionStep => self::Condition,
            default => throw new \LogicException(\sprintf('Unknown step kind %s.', $step::class)),
        };
    }
}
