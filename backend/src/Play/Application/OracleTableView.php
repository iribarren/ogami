<?php

declare(strict_types=1);

namespace App\Play\Application;

/**
 * An oracle table of the campaign's pinned release.
 */
final readonly class OracleTableView
{
    public function __construct(
        public string $key,
        public string $name,
    ) {
    }
}
