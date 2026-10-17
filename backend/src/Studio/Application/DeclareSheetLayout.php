<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Command;

/**
 * Declares the layout of a sheet that has none, from the groups its fields name. $expectedVersion is
 * the draft version the client read.
 */
final readonly class DeclareSheetLayout implements Command
{
    public function __construct(
        public string $ownerId,
        public string $key,
        public int $expectedVersion,
    ) {
    }
}
