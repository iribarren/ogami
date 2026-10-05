<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

use App\Randomness\Domain\Oracle\OracleTableResult;
use App\Randomness\Domain\Oracle\OracleTableStep;

/**
 * A roll on an oracle table of the pinned release: the oracle asked and every resolution step,
 * root first.
 *
 * @phpstan-type StepData array{tableKey: string, tableName: string, dice: string, total: int, text: string, nestedTableKey: ?string}
 */
final readonly class OracleTableContent implements JournalEntryContent
{
    public const string KIND = 'oracle-table';

    /**
     * @param non-empty-list<StepData> $steps
     */
    private function __construct(
        private string $oracleKey,
        private string $oracleName,
        private array $steps,
    ) {
    }

    /**
     * @throws InvalidJournalEntryContent when the oracle key is blank
     */
    public static function fromResult(string $oracleKey, string $oracleName, OracleTableResult $result): self
    {
        return self::of(
            $oracleKey,
            $oracleName,
            array_map(
                static fn (OracleTableStep $step): array => [
                    'tableKey' => $step->tableKey(),
                    'tableName' => $step->tableName(),
                    'dice' => $step->dice(),
                    'total' => $step->total(),
                    'text' => $step->text(),
                    'nestedTableKey' => $step->nestedTableKey(),
                ],
                $result->steps(),
            ),
        );
    }

    /**
     * @param array<mixed> $data
     *
     * @throws InvalidJournalEntryContent
     */
    public static function fromArray(array $data): self
    {
        $steps = array_map(
            static fn (array $step): array => [
                'tableKey' => ContentData::string($step, 'tableKey'),
                'tableName' => ContentData::string($step, 'tableName'),
                'dice' => ContentData::string($step, 'dice'),
                'total' => ContentData::int($step, 'total'),
                'text' => ContentData::string($step, 'text'),
                'nestedTableKey' => ContentData::nullableString($step, 'nestedTableKey'),
            ],
            ContentData::listOfArrays($data, 'steps'),
        );
        if ([] === $steps) {
            throw InvalidJournalEntryContent::malformed('steps', 'a non-empty list');
        }

        return self::of(ContentData::string($data, 'oracleKey'), ContentData::string($data, 'oracleName'), $steps);
    }

    /**
     * @param non-empty-list<StepData> $steps
     */
    private static function of(string $oracleKey, string $oracleName, array $steps): self
    {
        if ('' === trim($oracleKey)) {
            throw InvalidJournalEntryContent::blankOracleKey();
        }

        return new self($oracleKey, $oracleName, $steps);
    }

    public function kind(): string
    {
        return self::KIND;
    }

    public function oracleKey(): string
    {
        return $this->oracleKey;
    }

    public function oracleName(): string
    {
        return $this->oracleName;
    }

    /**
     * @return non-empty-list<StepData> root first; nestedTableKey is null on the last step
     */
    public function steps(): array
    {
        return $this->steps;
    }

    /**
     * @return array{kind: string, oracleKey: string, oracleName: string, steps: non-empty-list<StepData>}
     */
    public function toArray(): array
    {
        return ['kind' => self::KIND, 'oracleKey' => $this->oracleKey, 'oracleName' => $this->oracleName, 'steps' => $this->steps];
    }
}
