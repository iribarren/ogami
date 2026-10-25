<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

use App\Formula\Domain\FormulaEvaluationFailed;
use App\Formula\Domain\Reference;
use App\Formula\Domain\ReferenceKind;
use App\Formula\Domain\ValueResolver;
use App\Play\Domain\Campaign\CampaignEffects;

/**
 * What a flow formula reads: the campaign's Trackers, nothing else (a sheet value has no place in a
 * band bound or an Effect value).
 */
final readonly class TrackerValues implements ValueResolver
{
    public function __construct(
        private CampaignEffects $effects,
    ) {
    }

    public function value(Reference $reference): int
    {
        if (ReferenceKind::Tracker !== $reference->kind) {
            throw FormulaEvaluationFailed::unknownReference($reference);
        }

        return $this->effects->trackerValue($reference->key);
    }
}
