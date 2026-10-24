<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Characters;

/**
 * Why creating a Character was refused for a rule of structured character creation (ADR 0040), as
 * opposed to a value that does not fit its field. The API maps each case to a problem code.
 */
enum CreationRefusal
{
    case MethodRequired;
    case MethodUnavailable;
    case ValueSetAtCreation;
    case OutsideBounds;
    case BudgetExceeded;
}
