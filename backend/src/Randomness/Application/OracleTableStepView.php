<?php

declare(strict_types=1);

namespace App\Randomness\Application;

use App\Randomness\Domain\Oracle\OracleTableStep;

/**
 * One roll on one oracle table: the dice rolled, their total and the selected entry.
 */
final readonly class OracleTableStepView
{
    /**
     * @param string      $dice           the normalized notation rolled, "1dW" for a weighted table of total weight W
     * @param string      $text           the selected entry's text; may be empty when it nests a table
     * @param string|null $nestedTableKey the table the next step rolls on, null on the last step
     */
    public function __construct(
        public string $tableKey,
        public string $tableName,
        public string $dice,
        public int $total,
        public string $text,
        public ?string $nestedTableKey,
    ) {
    }

    public static function fromStep(OracleTableStep $step): self
    {
        return new self(
            $step->tableKey(),
            $step->tableName(),
            $step->dice(),
            $step->total(),
            $step->text(),
            $step->nestedTableKey(),
        );
    }
}
