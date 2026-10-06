<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * The dice rolled for one dice term of a recorded roll, such as "4d6kh3".
 */
#[OA\Schema(required: ['notation', 'sides', 'dice', 'subtotal'])]
final readonly class JournalDiceGroupResponse
{
    /**
     * @param list<JournalRolledDieResponse> $dice
     */
    private function __construct(
        #[OA\Property(example: '2d6')]
        public string $notation,
        #[OA\Property(example: 6)]
        public int $sides,
        #[OA\Property(
            description: 'Every die rolled, in roll order, dropped ones included.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: JournalRolledDieResponse::class)),
        )]
        public array $dice,
        #[OA\Property(description: 'The sum of the kept dice.', example: 7)]
        public int $subtotal,
    ) {
    }

    /**
     * @param array{notation: string, sides: int, dice: list<array{value: int, kept: bool}>, subtotal: int} $group
     */
    public static function of(array $group): self
    {
        return new self(
            $group['notation'],
            $group['sides'],
            array_map(
                static fn (array $die): JournalRolledDieResponse => JournalRolledDieResponse::of($die['value'], $die['kept']),
                $group['dice'],
            ),
            $group['subtotal'],
        );
    }
}
