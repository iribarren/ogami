<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\OracleNotFound;

/**
 * Reads the oracles of a saved draft as Randomness takes them: raw, so that a draft that is not a
 * valid release fails there rather than being silently repaired.
 */
final readonly class StoredOracles
{
    /**
     * @return array<mixed> the table set; empty when the draft has no tables
     */
    public static function tables(GameSystem $gameSystem): array
    {
        return self::section($gameSystem, 'tables');
    }

    /**
     * @return array<mixed>
     *
     * @throws OracleNotFound when the draft has no likelihood oracle with this key
     */
    public static function likelihoodOracle(GameSystem $gameSystem, string $key): array
    {
        foreach (self::section($gameSystem, 'likelihood') as $oracle) {
            if (\is_array($oracle) && ($oracle['key'] ?? null) === $key) {
                return $oracle;
            }
        }

        throw OracleNotFound::withKey($key);
    }

    /**
     * @throws OracleNotFound when the draft has no table with this key
     */
    public static function assertTable(GameSystem $gameSystem, string $key): void
    {
        foreach (self::section($gameSystem, 'tables') as $table) {
            if (\is_array($table) && ($table['key'] ?? null) === $key) {
                return;
            }
        }

        throw OracleNotFound::withKey($key);
    }

    /**
     * @param 'tables'|'likelihood' $name
     *
     * @return array<mixed>
     */
    private static function section(GameSystem $gameSystem, string $name): array
    {
        $oracles = $gameSystem->document()['oracles'] ?? null;
        $items = \is_array($oracles) ? ($oracles[$name] ?? null) : null;

        return \is_array($items) ? $items : [];
    }
}
