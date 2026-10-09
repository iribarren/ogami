<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

/**
 * How a campaign playing a Flow opens by default (ADR 0016).
 */
enum FlowView: string
{
    case Focus = 'focus';
    case Journal = 'journal';
}
