<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

/**
 * The draft's sheet declares no currency.
 */
final class SheetCurrencyNotFound extends \DomainException
{
    public static function none(): self
    {
        return new self('The sheet declares no currency.');
    }
}
