<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Check;

use App\Formula\Domain\Reference;

/**
 * One value a check's modifier formula read when it was performed: the reference and the integer
 * the sheet gave for it.
 */
final readonly class ModifierTerm
{
    public function __construct(
        public Reference $reference,
        public int $value,
    ) {
    }

    /**
     * The reference as a formula spells it, braces included: "{hot}", "{hp.max}" or "{tracker:chaos}".
     */
    public function token(): string
    {
        return '{'.$this->reference->toString().'}';
    }
}
