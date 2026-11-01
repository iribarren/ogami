<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Check;

/**
 * The special result a roll can have (ADR 0044, ADR 0049), at most one per roll. The value is what the
 * journal and the API carry. Check::specialOf() says which one a pool roll has; a challenge roll has a match.
 */
enum SpecialResult: string
{
    case Critical = 'critical';
    case MessyCritical = 'messyCritical';
    case BestialFailure = 'bestialFailure';

    /** Every challenge die shows the same face (ADR 0049). Shown only: a step cannot name it. */
    case Match = 'match';
}
