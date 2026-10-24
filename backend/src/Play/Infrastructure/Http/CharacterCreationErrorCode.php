<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Campaign\Characters\CreationRefusal;

/**
 * The machine-readable reason a Character was refused for a rule of structured character creation
 * (ADR 0040). The values are the HTTP contract: the SPA branches on them, so they never change with
 * the wording of a message.
 */
enum CharacterCreationErrorCode: string
{
    case CreationMethodRequired = 'creation_method_required';
    case CreationMethodUnavailable = 'creation_method_unavailable';
    case ValueSetAtCreation = 'value_set_at_creation';
    case OutsideCreationBounds = 'outside_creation_bounds';
    case PointBudgetExceeded = 'point_budget_exceeded';

    public static function of(CreationRefusal $refusal): self
    {
        return match ($refusal) {
            CreationRefusal::MethodRequired => self::CreationMethodRequired,
            CreationRefusal::MethodUnavailable => self::CreationMethodUnavailable,
            CreationRefusal::ValueSetAtCreation => self::ValueSetAtCreation,
            CreationRefusal::OutsideBounds => self::OutsideCreationBounds,
            CreationRefusal::BudgetExceeded => self::PointBudgetExceeded,
        };
    }
}
