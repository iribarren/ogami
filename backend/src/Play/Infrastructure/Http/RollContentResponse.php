<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Journal\RollContent;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * A dice expression rolled on the server, in the same shape as the roll of `POST /api/rolls`.
 */
#[OA\Schema(required: ['kind', 'expression', 'total', 'groups'])]
final readonly class RollContentResponse
{
    /**
     * @param list<JournalDiceGroupResponse> $groups
     */
    private function __construct(
        #[OA\Property(enum: [RollContent::KIND])]
        public string $kind,
        #[OA\Property(description: 'The normalized notation rolled.', example: '2d6+1')]
        public string $expression,
        #[OA\Property(description: 'The value of the expression, counting kept dice only.', example: 8)]
        public int $total,
        #[OA\Property(
            description: 'Every dice group, in notation order; empty when the expression rolls no dice.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: JournalDiceGroupResponse::class)),
        )]
        public array $groups,
    ) {
    }

    public static function of(RollContent $content): self
    {
        return new self(
            RollContent::KIND,
            $content->expression(),
            $content->total(),
            array_map(JournalDiceGroupResponse::of(...), $content->groups()),
        );
    }
}
