<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Command;

/**
 * Puts the currency of the sheet of the owner's GameSystem draft (ADR 0045): the number field
 * Characters spend, with an optional unit. $expectedVersion is the draft version the client read.
 */
final readonly class SaveSheetCurrency implements Command
{
    /**
     * @param array<array-key, mixed> $currency "field" and "unit" as in a release document (an unset unit left out). Not typed further: a malformed value is refused with its path
     */
    public function __construct(
        public string $ownerId,
        public string $key,
        public int $expectedVersion,
        public array $currency,
    ) {
    }
}
