<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\GameSystem\Flow\CharacterStep;
use App\Play\Domain\GameSystem\Flow\CheckStep;
use App\Play\Domain\GameSystem\Flow\ChoiceStep;
use App\Play\Domain\GameSystem\Flow\ConditionStep;
use App\Play\Domain\GameSystem\Flow\OracleStep;
use App\Play\Domain\GameSystem\Flow\PickStep;
use App\Play\Domain\GameSystem\Flow\PromptStep;
use App\Play\Domain\GameSystem\Flow\RollStep;
use App\Play\Domain\GameSystem\Flow\ShopStep;
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
    case Pick = 'pick';
    case Character = 'character';
    case Check = 'check';
    case Shop = 'shop';

    public static function of(Step $step): self
    {
        return match (true) {
            $step instanceof PromptStep => self::Prompt,
            $step instanceof OracleStep => self::Oracle,
            $step instanceof TableStep => self::Table,
            $step instanceof RollStep => self::Roll,
            $step instanceof ChoiceStep => self::Choice,
            $step instanceof ConditionStep => self::Condition,
            $step instanceof PickStep => self::Pick,
            $step instanceof CharacterStep => self::Character,
            $step instanceof CheckStep => self::Check,
            $step instanceof ShopStep => self::Shop,
            default => throw new \LogicException(\sprintf('Unknown step kind %s.', $step::class)),
        };
    }
}
