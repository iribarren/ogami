<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * A conditions sheet field of the draft with its options, which a sheet effect of a check's push
 * (mark, clear) picks from.
 */
final readonly class CheckConditionsFieldView
{
    /**
     * @param list<CheckConditionOptionView> $options the field's options, in order
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $options,
    ) {
    }
}
