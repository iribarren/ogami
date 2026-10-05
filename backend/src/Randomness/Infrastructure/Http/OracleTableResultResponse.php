<?php

declare(strict_types=1);

namespace App\Randomness\Infrastructure\Http;

use App\Randomness\Application\OracleTableResultView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * JSON body of a successful `POST /api/oracle-table-results`.
 */
#[OA\Schema(required: ['table', 'steps'])]
final readonly class OracleTableResultResponse
{
    /**
     * @param list<OracleTableStepResponse> $steps
     */
    private function __construct(
        #[OA\Property(description: 'The key of the table rolled on first.', example: 'weather')]
        public string $table,
        #[OA\Property(
            description: 'One step per table rolled, root first; a step with a nestedTableKey is followed by the roll on that table.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: OracleTableStepResponse::class)),
            minItems: 1,
        )]
        public array $steps,
    ) {
    }

    public static function fromView(OracleTableResultView $view): self
    {
        return new self($view->table, array_map(OracleTableStepResponse::fromView(...), $view->steps));
    }
}
