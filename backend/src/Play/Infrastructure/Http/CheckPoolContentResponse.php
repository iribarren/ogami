<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Journal\CheckPoolContent;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * The pool part of a recorded check: the count and size of the pool, its dice and the successes.
 */
#[OA\Schema(required: ['count', 'countFormula', 'terms', 'size', 'sides', 'successAt', 'dice', 'successes'])]
final readonly class CheckPoolContentResponse
{
    /**
     * @param list<CheckModifierTermResponse> $terms
     * @param list<JournalPoolDieResponse>    $dice
     */
    private function __construct(
        #[OA\Property(description: 'The number of dice the release asked for, before the Situational modifier: its number, or what its formula gave.', example: 4)]
        public int $count,
        #[OA\Property(description: 'The formula that gave the count; null when the release gave a number.', example: '{agility} + {stealth}', nullable: true)]
        public ?string $countFormula,
        #[OA\Property(
            description: 'What each reference of the count formula read, in the order the formula first names them; empty without a formula.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: CheckModifierTermResponse::class)),
        )]
        public array $terms,
        #[OA\Property(description: 'The dice rolled: the count plus the Situational modifier, kept between 1 and 100.', example: 5)]
        public int $size,
        #[OA\Property(description: 'The faces of each die.', example: 6)]
        public int $sides,
        #[OA\Property(description: 'A die succeeds on this face or higher.', example: 6)]
        public int $successAt,
        #[OA\Property(
            description: 'Every die rolled, in roll order.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: JournalPoolDieResponse::class)),
        )]
        public array $dice,
        #[OA\Property(description: 'How many dice succeeded; the total of the check.', example: 2)]
        public int $successes,
    ) {
    }

    public static function of(CheckPoolContent $pool): self
    {
        return new self(
            $pool->count,
            $pool->countFormula,
            array_map(CheckModifierTermResponse::of(...), $pool->terms),
            $pool->size,
            $pool->sides,
            $pool->successAt,
            array_map(JournalPoolDieResponse::of(...), $pool->dice),
            $pool->successes,
        );
    }
}
