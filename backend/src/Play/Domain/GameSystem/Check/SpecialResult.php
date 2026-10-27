<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Check;

/**
 * The special result a pool roll can have (ADR 0044), at most one per roll. The value is what the
 * journal and the API carry. Check::specialOf() says which one a roll has.
 */
enum SpecialResult: string
{
    case Critical = 'critical';
    case MessyCritical = 'messyCritical';
    case BestialFailure = 'bestialFailure';
}
