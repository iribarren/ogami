<?php

declare(strict_types=1);

namespace App\Randomness\Infrastructure\Http;

use App\Randomness\Application\RollView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * JSON body of a successful `POST /api/rolls`.
 */
#[OA\Schema(required: ['expression', 'total', 'groups'])]
final readonly class RollResponse
{
    /**
     * @param list<DiceGroupResponse> $groups
     */
    private function __construct(
        #[OA\Property(description: 'The normalized notation: lower case, no whitespace, "d" as "1d", "d%" as "d100", "k" as "kh".', example: '4d6kh3+2')]
        public string $expression,
        #[OA\Property(description: 'The value of the expression, counting kept dice only.', example: 16)]
        public int $total,
        #[OA\Property(
            description: 'Every dice group, in notation order; empty when the expression rolls no dice.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: DiceGroupResponse::class)),
        )]
        public array $groups,
    ) {
    }

    public static function fromView(RollView $view): self
    {
        return new self(
            $view->expression,
            $view->total,
            array_map(DiceGroupResponse::fromView(...), $view->groups),
        );
    }
}
