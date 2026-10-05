<?php

declare(strict_types=1);

namespace App\Randomness\Infrastructure\Http;

use App\Randomness\Application\OracleTableStepView;
use OpenApi\Attributes as OA;

/**
 * One roll on one oracle table.
 */
#[OA\Schema(required: ['tableKey', 'tableName', 'dice', 'total', 'text', 'nestedTableKey'])]
final readonly class OracleTableStepResponse
{
    private function __construct(
        #[OA\Property(example: 'weather')]
        public string $tableKey,
        #[OA\Property(example: 'Weather')]
        public string $tableName,
        #[OA\Property(description: 'The normalized notation rolled: the table\'s dice, or "1dW" for a weighted table of total weight W.', example: '1d6')]
        public string $dice,
        #[OA\Property(description: 'The total rolled, which selected the entry.', example: 6)]
        public int $total,
        #[OA\Property(description: 'The selected entry\'s text; may be empty when the entry nests a table.', example: 'Storm')]
        public string $text,
        #[OA\Property(description: 'The table the next step rolls on; null on the last step.', example: 'storm-kind')]
        public ?string $nestedTableKey,
    ) {
    }

    public static function fromView(OracleTableStepView $view): self
    {
        return new self($view->tableKey, $view->tableName, $view->dice, $view->total, $view->text, $view->nestedTableKey);
    }
}
