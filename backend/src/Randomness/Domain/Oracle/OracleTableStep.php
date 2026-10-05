<?php

declare(strict_types=1);

namespace App\Randomness\Domain\Oracle;

/**
 * One roll on one oracle table during a resolution: the table, the dice rolled, their total
 * and the selected entry.
 */
final readonly class OracleTableStep
{
    public function __construct(
        private string $tableKey,
        private string $tableName,
        private string $dice,
        private int $total,
        private string $text,
        private ?string $nestedTableKey,
    ) {
    }

    public function tableKey(): string
    {
        return $this->tableKey;
    }

    public function tableName(): string
    {
        return $this->tableName;
    }

    /**
     * The normalized notation rolled: the table's dice, or "1dW" for a weighted table of total weight W.
     */
    public function dice(): string
    {
        return $this->dice;
    }

    public function total(): int
    {
        return $this->total;
    }

    /**
     * The selected entry's text; it may be empty when the entry nests a table.
     */
    public function text(): string
    {
        return $this->text;
    }

    /**
     * The table the next step rolls on, or null when this is the last step.
     */
    public function nestedTableKey(): ?string
    {
        return $this->nestedTableKey;
    }
}
