<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

/**
 * What a sheet Effect does: add, subtract or set a number or track, or mark or clear an option of a
 * conditions field (ADR 0041).
 */
enum SheetOperation: string
{
    case Add = 'add';
    case Subtract = 'subtract';
    case Set = 'set';
    case Mark = 'mark';
    case Clear = 'clear';
}
