<?php

declare(strict_types=1);

namespace App\Randomness\Infrastructure\Http;

use App\Randomness\Application\DiceGroupView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * The dice rolled for one dice term of a roll, such as "4d6kh3".
 */
#[OA\Schema(required: ['notation', 'sides', 'dice', 'subtotal'])]
final readonly class DiceGroupResponse
{
    /**
     * @param list<RolledDieResponse> $dice
     */
    private function __construct(
        #[OA\Property(example: '4d6kh3')]
        public string $notation,
        #[OA\Property(example: 6)]
        public int $sides,
        #[OA\Property(
            description: 'Every die rolled, in roll order, dropped ones included.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: RolledDieResponse::class)),
        )]
        public array $dice,
        #[OA\Property(description: 'The sum of the kept dice.', example: 14)]
        public int $subtotal,
    ) {
    }

    public static function fromView(DiceGroupView $view): self
    {
        return new self(
            $view->notation,
            $view->sides,
            array_map(RolledDieResponse::fromView(...), $view->dice),
            $view->subtotal,
        );
    }
}
