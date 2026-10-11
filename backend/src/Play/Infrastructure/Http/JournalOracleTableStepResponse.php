<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * One roll on one oracle table of a recorded oracle table result.
 */
#[OA\Schema(required: ['tableKey', 'tableName', 'dice', 'total', 'text', 'nestedTableKey', 'sceneType'])]
final readonly class JournalOracleTableStepResponse
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
        #[OA\Property(description: 'The key of the Scene Type the entry offers, which the player may switch the scene to; null when it offers none, and on rows recorded before the key was kept.', example: 'getaway', nullable: true)]
        public ?string $sceneType,
    ) {
    }

    /**
     * @param array{tableKey: string, tableName: string, dice: string, total: int, text: string, nestedTableKey: ?string, sceneType: ?string} $step
     */
    public static function of(array $step): self
    {
        return new self($step['tableKey'], $step['tableName'], $step['dice'], $step['total'], $step['text'], $step['nestedTableKey'], $step['sceneType']);
    }
}
