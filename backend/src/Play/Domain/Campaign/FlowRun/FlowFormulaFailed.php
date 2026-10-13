<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

use App\Formula\Domain\Formula;
use App\Formula\Domain\FormulaEvaluationFailed;

/**
 * A formula of a band bound or a tracker Effect value that parsed but cannot produce a value
 * (division by zero, overflow). The action fails and the campaign stays as it was.
 */
final class FlowFormulaFailed extends \DomainException
{
    public static function of(Formula $formula, FormulaEvaluationFailed $failure): self
    {
        return new self(\sprintf('The formula "%s" cannot be evaluated: %s', $formula->toString(), $failure->getMessage()), 0, $failure);
    }
}
