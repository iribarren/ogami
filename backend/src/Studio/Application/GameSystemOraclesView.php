<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * The oracles of a GameSystem draft as stored, in order, with the draft version a client sends
 * back as the expected version of its next oracle command. The draft may be invalid, so what is
 * not an oracle with a string key is left out.
 */
final readonly class GameSystemOraclesView
{
    /**
     * @param list<OracleView> $tables
     * @param list<OracleView> $likelihood
     */
    public function __construct(
        public int $version,
        public int $schemaVersion,
        public array $tables,
        public array $likelihood,
    ) {
    }
}
