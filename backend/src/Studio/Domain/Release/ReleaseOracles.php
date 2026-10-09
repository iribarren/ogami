<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release;

use App\Randomness\Domain\Oracle\InvalidLikelihoodOracle;
use App\Randomness\Domain\Oracle\InvalidOracleTable;
use App\Randomness\Domain\Oracle\LikelihoodOracle;
use App\Randomness\Domain\Oracle\OracleTableSet;

/**
 * The "oracles" part of a release, shared by every schema version: one Randomness oracle table set
 * and the likelihood oracles, with oracle keys unique across both. A schema version may allow more
 * entry and chaos fields than Randomness knows; they are kept as given (in field order) for that
 * version to check, and left out of what Randomness validates.
 *
 * @internal used by ReleaseContent and its schema version validators only
 */
final class ReleaseOracles
{
    public const int MAX_ORACLE_NAME_LENGTH = 500;
    public const int MAX_LIKELIHOOD_ORACLES = 20;

    private const array ORACLES = ['tables' => true, 'likelihood' => true];
    private const array TABLE = ['key' => true, 'name' => true, 'dice' => false, 'entries' => true];
    private const array LIKELIHOOD = ['key' => true, 'name' => true, 'sides' => true, 'levels' => true, 'chaos' => false, 'exceptionalPercent' => false];
    private const array LEVEL = ['key' => true, 'label' => true, 'target' => true];

    /** Fields Randomness defines, so every schema version allows them. */
    public const array ENTRY = ['min' => false, 'max' => false, 'weight' => false, 'text' => false, 'table' => false];
    public const array CHAOS = ['min' => true, 'max' => true, 'neutral' => true, 'shiftPerPoint' => true];

    /**
     * @param array<string, bool> $entryFields ENTRY plus the fields the schema version adds
     * @param array<string, bool> $chaosFields CHAOS plus the fields the schema version adds
     *
     * @return array{tables: list<array<string, mixed>>, likelihood: list<array<string, mixed>>}
     */
    public static function validate(mixed $value, array $entryFields = self::ENTRY, array $chaosFields = self::CHAOS): array
    {
        $oracles = ReleaseFields::object($value, 'oracles', self::ORACLES);

        $tables = [];
        foreach (ReleaseFields::list($oracles['tables'], 'oracles.tables') as $index => $table) {
            $path = \sprintf('oracles.tables[%d]', $index);
            $table = ReleaseFields::object($table, $path, self::TABLE);
            $table['entries'] = ReleaseFields::objects($table['entries'], $path.'.entries', $entryFields);
            $tables[] = $table;
        }

        $tableKeys = [];
        if ([] !== $tables) {
            try {
                $tableKeys = OracleTableSet::fromArray(array_map(self::randomnessTable(...), $tables))->keys();
            } catch (InvalidOracleTable $invalid) {
                throw InvalidReleaseContent::at('oracles.tables', $invalid->getMessage(), $invalid);
            }
        }

        $oracleKeys = array_fill_keys($tableKeys, true);
        $likelihood = ReleaseFields::list($oracles['likelihood'], 'oracles.likelihood');
        if (\count($likelihood) > self::MAX_LIKELIHOOD_ORACLES) {
            throw InvalidReleaseContent::at('oracles.likelihood', \sprintf('at most %d likelihood oracles, %d given', self::MAX_LIKELIHOOD_ORACLES, \count($likelihood)));
        }

        foreach ($likelihood as $index => $oracle) {
            $path = \sprintf('oracles.likelihood[%d]', $index);
            $oracle = ReleaseFields::object($oracle, $path, self::LIKELIHOOD);
            $key = ReleaseFields::key($oracle['key'], $path.'.key');
            if (isset($oracleKeys[$key])) {
                throw InvalidReleaseContent::at($path.'.key', \sprintf('duplicate oracle key "%s"', $key));
            }

            $oracleKeys[$key] = true;
            ReleaseFields::text($oracle['name'], $path.'.name', 1, self::MAX_ORACLE_NAME_LENGTH);
            $oracle['levels'] = ReleaseFields::objects($oracle['levels'], $path.'.levels', self::LEVEL);
            $definition = array_diff_key($oracle, ['key' => true, 'name' => true]);
            if (isset($oracle['chaos'])) {
                $chaos = ReleaseFields::object($oracle['chaos'], $path.'.chaos', $chaosFields);
                $oracle['chaos'] = $chaos;
                $definition['chaos'] = array_intersect_key($chaos, self::CHAOS);
            }

            try {
                LikelihoodOracle::fromArray($definition);
            } catch (InvalidLikelihoodOracle $invalid) {
                throw InvalidReleaseContent::at($path, $invalid->getMessage(), $invalid);
            }

            $likelihood[$index] = $oracle;
        }

        return ['tables' => $tables, 'likelihood' => $likelihood];
    }

    /**
     * @param array<string, mixed> $table
     *
     * @return array<string, mixed>
     */
    private static function randomnessTable(array $table): array
    {
        /** @var list<array<string, mixed>> $entries */
        $entries = $table['entries'];
        $table['entries'] = array_map(static fn (array $entry): array => array_intersect_key($entry, self::ENTRY), $entries);

        return $table;
    }
}
