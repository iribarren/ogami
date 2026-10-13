<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

use App\Formula\Domain\FormulaEvaluationFailed;
use App\Formula\Domain\Reference;
use App\Formula\Domain\ReferenceKind;
use App\Formula\Domain\ValueResolver;

/**
 * What a flow formula reads when only the Tracker values are at hand, as in a view: the Trackers by
 * key, nothing else.
 */
final readonly class TrackerValueMap implements ValueResolver
{
    /**
     * @param array<string, int> $values the campaign's value of every Tracker of the release, by key
     */
    public function __construct(
        private array $values,
    ) {
    }

    public function value(Reference $reference): int
    {
        if (ReferenceKind::Tracker !== $reference->kind || !isset($this->values[$reference->key])) {
            throw FormulaEvaluationFailed::unknownReference($reference);
        }

        return $this->values[$reference->key];
    }
}
