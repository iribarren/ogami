<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

use App\Randomness\Domain\Oracle\OracleTableResult;
use App\Randomness\Domain\Oracle\OracleTableStep;

/**
 * A roll on an oracle table of the pinned release: the oracle asked and every resolution step,
 * root first. A roll made for a random event names the likelihood entry (the fate question) whose
 * random event it answers.
 *
 * @phpstan-type StepData array{tableKey: string, tableName: string, dice: string, total: int, text: string, nestedTableKey: ?string, sceneType: ?string}
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
        private ?string $forEntry,
    ) {
    }

    /**
     * @param list<?string> $sceneTypes the Scene Type key each step's entry offers, in step order; a missing item is null
     * @param ?string       $forEntry   the id of the likelihood entry whose random event this roll answers, null for any other roll
     *
     * @throws InvalidJournalEntryContent when the oracle key is blank
     */
    public static function fromResult(string $oracleKey, string $oracleName, OracleTableResult $result, array $sceneTypes, ?string $forEntry = null): self
    {
        return self::of(
            $oracleKey,
            $oracleName,
            array_map(
                static fn (OracleTableStep $step, int $index): array => [
                    'tableKey' => $step->tableKey(),
                    'tableName' => $step->tableName(),
                    'dice' => $step->dice(),
                    'total' => $step->total(),
                    'text' => $step->text(),
                    'nestedTableKey' => $step->nestedTableKey(),
                    'sceneType' => $sceneTypes[$index] ?? null,
                ],
                $result->steps(),
                array_keys($result->steps()),
            ),
            $forEntry,
        );
    }

    /**
     * Rows stored before the Scene Type was kept have no `sceneType`, and rows stored before the
     * question was kept have no `forEntry`: both read as null.
     *
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
                'sceneType' => \array_key_exists('sceneType', $step) ? ContentData::nullableString($step, 'sceneType') : null,
            ],
            ContentData::listOfArrays($data, 'steps'),
        );
        if ([] === $steps) {
            throw InvalidJournalEntryContent::malformed('steps', 'a non-empty list');
        }

        return self::of(
            ContentData::string($data, 'oracleKey'),
            ContentData::string($data, 'oracleName'),
            $steps,
            \array_key_exists('forEntry', $data) ? ContentData::nullableString($data, 'forEntry') : null,
        );
    }

    /**
     * @param non-empty-list<StepData> $steps
     */
    private static function of(string $oracleKey, string $oracleName, array $steps, ?string $forEntry): self
    {
        if ('' === trim($oracleKey)) {
            throw InvalidJournalEntryContent::blankOracleKey();
        }

        return new self($oracleKey, $oracleName, $steps, $forEntry);
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
     * The id of the likelihood entry whose random event this roll answers; null for any other roll.
     */
    public function forEntry(): ?string
    {
        return $this->forEntry;
    }

    /**
     * @return non-empty-list<StepData> root first; nestedTableKey is null on the last step, sceneType when the entry offers none
     */
    public function steps(): array
    {
        return $this->steps;
    }

    /**
     * @return array{kind: string, oracleKey: string, oracleName: string, forEntry: ?string, steps: non-empty-list<StepData>}
     */
    public function toArray(): array
    {
        return ['kind' => self::KIND, 'oracleKey' => $this->oracleKey, 'oracleName' => $this->oracleName, 'forEntry' => $this->forEntry, 'steps' => $this->steps];
    }
}
